#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Seasonvar\SeasonvarTitleMerger;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$slug = $argv[1] ?? null;

if ($slug === null || in_array($slug, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/merge-seasonvar-title-family.php <canonical-title-slug>\n");
    fwrite(STDERR, "Example: php scripts/merge-seasonvar-title-family.php mamockamom-5\n");
    exit($slug === null ? 1 : 0);
}

try {
    $result = $app->make(SeasonvarTitleMerger::class)->mergeForCanonicalSlug(
        $slug,
        function (string $event, array $context): void {
            if ($event !== 'seasonvar-title-merged') {
                return;
            }

            echo sprintf(
                "Merged into %s: titles=%d seasons=%d episodes=%d\n",
                (string) ($context['slug'] ?? ''),
                (int) ($context['merged_titles'] ?? 0),
                (int) ($context['merged_seasons'] ?? 0),
                (int) ($context['merged_episodes'] ?? 0),
            );
        },
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
