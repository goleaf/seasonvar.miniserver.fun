<?php

declare(strict_types=1);

namespace App\Services\Operations;

use JsonException;

final class PlayerReleaseReadiness
{
    /**
     * @return array{
     *     ready: bool,
     *     errors: list<string>,
     *     source_fingerprint: string|null,
     *     source_count: int,
     *     asset_count: int
     * }
     */
    public function check(
        ?string $projectRoot = null,
        ?string $descriptorPath = null,
        ?string $buildDirectory = null,
    ): array {
        $errors = [];
        $root = $this->canonicalDirectory($projectRoot ?? base_path());

        if ($root === null) {
            return $this->result(['project_root_invalid']);
        }

        $descriptorPath ??= $root.'/resources/player-release.json';
        $buildDirectory ??= $root.'/public/build';
        $build = $this->canonicalDirectory($buildDirectory);

        if ($build === null || ! $this->within($root, $build) || is_link($buildDirectory)) {
            return $this->result(['build_directory_invalid']);
        }

        $descriptor = $this->jsonFile($descriptorPath, $root, 'descriptor', $errors);

        if ($descriptor === null
            || ($descriptor['schema'] ?? null) !== 1
            || ! is_array($descriptor['source_files'] ?? null)
            || $descriptor['source_files'] === []) {
            $errors[] = 'descriptor_schema_invalid';

            return $this->result($errors);
        }

        if (array_filter(
            $descriptor['source_files'],
            fn (mixed $path): bool => ! is_string($path),
        ) !== []) {
            $errors[] = 'descriptor_sources_invalid';

            return $this->result($errors);
        }

        $sourcePaths = array_values(array_unique($descriptor['source_files']));

        if (count($sourcePaths) !== count($descriptor['source_files'])) {
            $errors[] = 'descriptor_sources_invalid';

            return $this->result($errors);
        }

        $sourceInventory = [];

        foreach ($sourcePaths as $relativePath) {
            if (! $this->safeRelativePath($relativePath)) {
                $errors[] = 'source_path_unsafe';

                continue;
            }

            if ($this->pathHasSymlink($root, $relativePath)) {
                $errors[] = 'source_symlink';

                continue;
            }

            $path = realpath($root.'/'.$relativePath);

            if ($path === false || ! is_file($path) || ! $this->within($root, $path)) {
                $errors[] = 'source_missing';

                continue;
            }

            $hash = hash_file('sha256', $path);
            $bytes = filesize($path);

            if (! is_string($hash) || ! is_int($bytes)) {
                $errors[] = 'source_unreadable';

                continue;
            }

            $sourceInventory[] = [
                'path' => $relativePath,
                'sha256' => $hash,
                'bytes' => $bytes,
            ];
        }

        usort(
            $sourceInventory,
            fn (array $left, array $right): int => $left['path'] <=> $right['path'],
        );
        $sourceFingerprint = count($sourceInventory) === count($sourcePaths)
            ? hash('sha256', json_encode(
                $sourceInventory,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            : null;
        $manifest = $this->jsonFile(
            $build.'/manifest.json',
            $build,
            'manifest',
            $errors,
        );
        $release = $this->jsonFile(
            $build.'/player-release.json',
            $build,
            'release_record',
            $errors,
        );

        if ($manifest === null || $release === null) {
            return $this->result($errors, $sourceFingerprint, count($sourceInventory));
        }

        if (($release['schema'] ?? null) !== 1
            || ($release['generated_by'] ?? null) !== 'player-release-vite-plugin'
            || ! is_string($release['source_fingerprint'] ?? null)
            || ! is_int($release['source_count'] ?? null)
            || ! is_array($release['assets'] ?? null)) {
            $errors[] = 'release_record_schema_invalid';

            return $this->result($errors, $sourceFingerprint, count($sourceInventory));
        }

        if ($sourceFingerprint === null
            || ! hash_equals($sourceFingerprint, $release['source_fingerprint'])
            || $release['source_count'] !== count($sourceInventory)) {
            $errors[] = 'source_fingerprint_mismatch';
        }

        $graphAssets = $this->manifestGraphAssets($manifest, $errors);
        $recordedAssets = [];

        foreach ($release['assets'] as $asset) {
            if (! is_array($asset)
                || ! is_string($asset['file'] ?? null)
                || ! is_string($asset['sha256'] ?? null)
                || ! is_int($asset['bytes'] ?? null)
                || ! in_array($asset['type'] ?? null, ['asset', 'chunk'], true)) {
                $errors[] = 'release_asset_schema_invalid';

                continue;
            }

            $relativePath = $asset['file'];

            if (isset($recordedAssets[$relativePath])) {
                $errors[] = 'release_asset_duplicate';

                continue;
            }

            $recordedAssets[$relativePath] = true;

            if (! $this->safeRelativePath($relativePath)) {
                $errors[] = 'asset_path_unsafe';

                continue;
            }

            if ($this->pathHasSymlink($build, $relativePath)) {
                $errors[] = 'asset_symlink';

                continue;
            }

            $path = realpath($build.'/'.$relativePath);

            if ($path === false || ! is_file($path) || ! $this->within($build, $path)) {
                $errors[] = 'asset_missing';

                continue;
            }

            $bytes = filesize($path);
            $hash = hash_file('sha256', $path);

            if (! is_int($bytes) || $bytes !== $asset['bytes']) {
                $errors[] = 'asset_size_mismatch';
            }

            if (! is_string($hash) || ! hash_equals($asset['sha256'], $hash)) {
                $errors[] = 'asset_hash_mismatch';
            }
        }

        foreach ($graphAssets as $graphAsset) {
            if (! isset($recordedAssets[$graphAsset])) {
                $errors[] = 'manifest_asset_unrecorded';
            }
        }

        return $this->result(
            $errors,
            $sourceFingerprint,
            count($sourceInventory),
            count($recordedAssets),
        );
    }

    /**
     * @param  list<string>  $errors
     * @return array{
     *     ready: bool,
     *     errors: list<string>,
     *     source_fingerprint: string|null,
     *     source_count: int,
     *     asset_count: int
     * }
     */
    private function result(
        array $errors,
        ?string $sourceFingerprint = null,
        int $sourceCount = 0,
        int $assetCount = 0,
    ): array {
        $errors = array_values(array_unique($errors));

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'source_fingerprint' => $sourceFingerprint,
            'source_count' => $sourceCount,
            'asset_count' => $assetCount,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function jsonFile(
        string $path,
        string $allowedRoot,
        string $errorPrefix,
        array &$errors,
    ): ?array {
        if (is_link($path)) {
            $errors[] = $errorPrefix.'_symlink';

            return null;
        }

        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! $this->within($allowedRoot, $realPath)) {
            $errors[] = $errorPrefix.'_missing';

            return null;
        }

        $contents = file_get_contents($realPath);

        if (! is_string($contents)) {
            $errors[] = $errorPrefix.'_unreadable';

            return null;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $errors[] = $errorPrefix.'_invalid_json';

            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $errors[] = $errorPrefix.'_invalid_json';

            return null;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function manifestGraphAssets(array $manifest, array &$errors): array
    {
        $entry = 'resources/js/app.js';

        if (! isset($manifest[$entry]) || ! is_array($manifest[$entry])) {
            $errors[] = 'app_entry_missing';

            return [];
        }

        $queue = [$entry];
        $visited = [];
        $assets = [];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;
            $chunk = $manifest[$key] ?? null;

            if (! is_array($chunk)) {
                $errors[] = 'manifest_import_missing';

                continue;
            }

            foreach (['file', 'css', 'assets'] as $assetKey) {
                $values = $assetKey === 'file'
                    ? [$chunk[$assetKey] ?? null]
                    : ($chunk[$assetKey] ?? []);

                if (! is_array($values)) {
                    $errors[] = 'manifest_entry_invalid';

                    continue;
                }

                foreach ($values as $value) {
                    if (! is_string($value) || ! $this->safeRelativePath($value)) {
                        $errors[] = 'manifest_asset_unsafe';

                        continue;
                    }

                    $assets[$value] = true;
                }
            }

            foreach (['imports', 'dynamicImports'] as $importKey) {
                $imports = $chunk[$importKey] ?? [];

                if (! is_array($imports)) {
                    $errors[] = 'manifest_entry_invalid';

                    continue;
                }

                foreach ($imports as $import) {
                    if (is_string($import)) {
                        $queue[] = $import;
                    } else {
                        $errors[] = 'manifest_entry_invalid';
                    }
                }
            }
        }

        if (! isset($visited['resources/js/player.js'])) {
            $errors[] = 'player_entry_missing';
        }

        $files = array_keys($assets);
        sort($files);

        return $files;
    }

    private function canonicalDirectory(string $path): ?string
    {
        $realPath = realpath($path);

        return $realPath !== false && is_dir($realPath)
            ? rtrim($realPath, DIRECTORY_SEPARATOR)
            : null;
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function pathHasSymlink(string $root, string $relativePath): bool
    {
        $path = $root;

        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private function within(string $root, string $path): bool
    {
        return $path === $root
            || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
