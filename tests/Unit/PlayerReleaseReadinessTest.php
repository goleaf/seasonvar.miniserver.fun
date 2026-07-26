<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\PlayerReleaseReadiness;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlayerReleaseReadinessTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/seasonvar-player-release-'.Str::uuid();
        File::ensureDirectoryExists($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_it_accepts_a_complete_matching_player_release(): void
    {
        [$descriptor, $build] = $this->writeValidFixture();

        $result = app(PlayerReleaseReadiness::class)->check(
            $this->fixtureRoot,
            $descriptor,
            $build,
        );

        $this->assertTrue($result['ready'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['source_count']);
        $this->assertSame(2, $result['asset_count']);
    }

    public function test_it_rejects_source_and_asset_drift(): void
    {
        [$descriptor, $build] = $this->writeValidFixture();

        File::put($this->fixtureRoot.'/resources/js/player.js', 'export const player = 2;');
        $sourceDrift = app(PlayerReleaseReadiness::class)->check(
            $this->fixtureRoot,
            $descriptor,
            $build,
        );

        $this->assertFalse($sourceDrift['ready']);
        $this->assertContains('source_fingerprint_mismatch', $sourceDrift['errors']);

        [$descriptor, $build] = $this->writeValidFixture();
        File::put($build.'/assets/player.js', 'tampered-player');
        $assetDrift = app(PlayerReleaseReadiness::class)->check(
            $this->fixtureRoot,
            $descriptor,
            $build,
        );

        $this->assertFalse($assetDrift['ready']);
        $this->assertContains('asset_size_mismatch', $assetDrift['errors']);
        $this->assertContains('asset_hash_mismatch', $assetDrift['errors']);
    }

    public function test_it_rejects_missing_player_graph_and_symlinked_sources(): void
    {
        [$descriptor, $build] = $this->writeValidFixture();
        $manifest = json_decode(File::get($build.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        unset($manifest['resources/js/player.js']);
        $manifest['resources/js/app.js']['dynamicImports'] = [];
        File::put(
            $build.'/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $missingPlayer = app(PlayerReleaseReadiness::class)->check(
            $this->fixtureRoot,
            $descriptor,
            $build,
        );

        $this->assertFalse($missingPlayer['ready']);
        $this->assertContains('player_entry_missing', $missingPlayer['errors']);

        [$descriptor, $build] = $this->writeValidFixture();
        File::delete($this->fixtureRoot.'/resources/js/player.js');
        symlink($this->fixtureRoot.'/resources/js/app.js', $this->fixtureRoot.'/resources/js/player.js');
        $symlinked = app(PlayerReleaseReadiness::class)->check(
            $this->fixtureRoot,
            $descriptor,
            $build,
        );

        $this->assertFalse($symlinked['ready']);
        $this->assertContains('source_symlink', $symlinked['errors']);
    }

    /** @return array{string, string} */
    private function writeValidFixture(): array
    {
        File::deleteDirectory($this->fixtureRoot);
        File::ensureDirectoryExists($this->fixtureRoot.'/resources/js');
        File::ensureDirectoryExists($this->fixtureRoot.'/public/build/assets');

        $sourceFiles = [
            'resources/js/app.js' => "import('./player.js');\n",
            'resources/js/player.js' => 'export const player = 1;',
        ];

        foreach ($sourceFiles as $path => $contents) {
            File::put($this->fixtureRoot.'/'.$path, $contents);
        }

        $descriptorPath = $this->fixtureRoot.'/resources/player-release.json';
        File::put($descriptorPath, json_encode([
            'schema' => 1,
            'source_files' => array_keys($sourceFiles),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $build = $this->fixtureRoot.'/public/build';
        $assets = [
            'assets/app.js' => "import('./player.js');\n",
            'assets/player.js' => 'export const player = 1;',
        ];

        foreach ($assets as $path => $contents) {
            File::put($build.'/'.$path, $contents);
        }

        File::put($build.'/manifest.json', json_encode([
            'resources/js/app.js' => [
                'file' => 'assets/app.js',
                'isEntry' => true,
                'dynamicImports' => ['resources/js/player.js'],
            ],
            'resources/js/player.js' => [
                'file' => 'assets/player.js',
                'isDynamicEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $sourceInventory = collect($sourceFiles)
            ->map(fn (string $contents, string $path): array => [
                'path' => $path,
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
            ])
            ->sortBy('path')
            ->values()
            ->all();
        $assetInventory = collect($assets)
            ->map(fn (string $contents, string $path): array => [
                'file' => $path,
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
                'type' => 'chunk',
            ])
            ->sortBy('file')
            ->values()
            ->all();

        File::put($build.'/player-release.json', json_encode([
            'schema' => 1,
            'generated_by' => 'player-release-vite-plugin',
            'source_fingerprint' => hash(
                'sha256',
                json_encode($sourceInventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
            'source_count' => count($sourceInventory),
            'assets' => $assetInventory,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$descriptorPath, $build];
    }
}
