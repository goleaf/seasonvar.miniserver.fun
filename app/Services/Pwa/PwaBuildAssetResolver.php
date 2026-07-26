<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PwaBuildAssetResolver
{
    /**
     * @return array{version: string, urls: list<string>}
     */
    public function resolve(): array
    {
        $manifestPath = public_path('build/manifest.json');

        if (! File::isFile($manifestPath)) {
            throw new RuntimeException('Vite manifest is unavailable.');
        }

        $contents = File::get($manifestPath);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $entry = is_array($manifest['resources/js/app.js'] ?? null)
            ? $manifest['resources/js/app.js']
            : null;

        if ($entry === null || ! is_string($entry['file'] ?? null)) {
            throw new RuntimeException('Vite application entry is unavailable.');
        }

        $files = [
            $entry['file'],
            ...Arr::wrap($entry['css'] ?? []),
            ...Arr::wrap($entry['assets'] ?? []),
        ];
        $urls = collect($files)
            ->filter(fn (mixed $file): bool => is_string($file) && preg_match('/\Aassets\/[A-Za-z0-9._-]+\z/', $file) === 1)
            ->map(fn (string $file): string => '/build/'.$file)
            ->prepend('/icons/pwa-maskable-512.png')
            ->prepend('/icons/pwa-512.png')
            ->prepend('/icons/pwa-192.png')
            ->prepend('/manifest.webmanifest')
            ->prepend('/offline')
            ->unique()
            ->values()
            ->all();

        return [
            'version' => substr(hash('sha256', $contents.implode("\n", $urls)), 0, 16),
            'urls' => $urls,
        ];
    }
}
