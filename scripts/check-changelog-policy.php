<?php

declare(strict_types=1);

$path = $argv[1] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Проверка CHANGELOG: файл не найден.\n");
    exit(2);
}

$allowedTokens = array_fill_keys([
    'Actions', 'Artisan', 'Blade', 'Boost', 'ClaudeBot', 'Codex', 'Composer', 'Chromium', 'Context7', 'Eloquent', 'FontAwesome',
    'Git', 'GitHub', 'Google', 'HDRezka', 'IMDb', 'JavaScript', 'Kinopoisk', 'Laravel', 'Larastan',
    'Livewire', 'Markdown', 'Memcached', 'OAuth', 'OpenAI', 'OpenAPI', 'PHPStan',
    'PHPUnit', 'Pint', 'Playwright', 'Plyr', 'Rector', 'Redis', 'Sanctum',
    'Seasonvar', 'SQLite', 'Tailwind', 'Tor', 'Unicode', 'Vite', 'WebP', 'cron', 'gzip', 'npm', 'systemd',
], true);

$fence = null;
$lines = file($path, FILE_IGNORE_NEW_LINES);

foreach ($lines === false ? [] : $lines as $index => $line) {
    $lineNumber = $index + 1;
    $trimmed = ltrim($line);

    if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
        $marker = substr($trimmed, 0, 3);
        $fence = $fence === null ? $marker : ($fence === $marker ? null : $fence);

        continue;
    }

    if ($fence !== null || $trimmed === '' || str_starts_with($trimmed, '<!--')) {
        continue;
    }

    if (preg_match('/^#{1,6}\s+\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        continue;
    }

    $plain = preg_replace([
        '/`[^`]*`/u',
        '~https?://[^\s)]+~u',
        '/\]\([^)]*\)/u',
    ], ' ', $line) ?? $line;

    if (! preg_match('/[\p{L}\p{N}]/u', $plain)) {
        continue;
    }

    if (! preg_match('/\p{Cyrillic}/u', $plain)) {
        fwrite(STDERR, "Проверка CHANGELOG: Строка {$lineNumber} должна содержать русский текст.\n");
        exit(1);
    }

    preg_match_all('/[A-Za-z](?:[A-Za-z0-9]|[._+-](?=[A-Za-z0-9]))*/', $plain, $matches);

    foreach ($matches[0] as $token) {
        if (isset($allowedTokens[$token]) || preg_match('/^[A-Z][A-Z0-9-]{1,11}$/', $token) || preg_match('/^v\d+$/i', $token)) {
            continue;
        }

        fwrite(STDERR, "Проверка CHANGELOG: Строка {$lineNumber} содержит английский обычный текст: {$token}.\n");
        exit(1);
    }
}
