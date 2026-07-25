<?php

declare(strict_types=1);

if ($argc !== 3 || ! is_file($argv[1])) {
    fwrite(STDERR, "Автообновление CHANGELOG: файл не найден.\n");
    exit(2);
}

$changelogPath = $argv[1];
$date = $argv[2];
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Автообновление CHANGELOG: дата должна иметь формат YYYY-MM-DD.\n");
    exit(2);
}

$rawPaths = stream_get_contents(STDIN);

if ($rawPaths === false) {
    fwrite(STDERR, "Автообновление CHANGELOG: не удалось прочитать staged-пути.\n");
    exit(2);
}

/** @var array<string, Closure(string): bool> $categoryMatchers */
$categoryMatchers = [
    'серверная логика' => static fn (string $path): bool => str_starts_with($path, 'app/')
        || str_starts_with($path, 'bootstrap/'),
    'конфигурация' => static fn (string $path): bool => str_starts_with($path, 'config/')
        || $path === '.env.example',
    'структура и работа с данными' => static fn (string $path): bool => str_starts_with($path, 'database/'),
    'маршруты' => static fn (string $path): bool => str_starts_with($path, 'routes/'),
    'переводы' => static fn (string $path): bool => str_starts_with($path, 'lang/'),
    'интерфейс и клиентские ресурсы' => static fn (string $path): bool => str_starts_with($path, 'resources/')
        || str_starts_with($path, 'public/')
        || str_starts_with($path, 'vite.config.'),
    'инструменты разработки и проверки' => static fn (string $path): bool => str_starts_with($path, 'scripts/')
        || str_starts_with($path, '.githooks/')
        || str_starts_with($path, 'tests/')
        || str_starts_with($path, 'phpunit.xml')
        || str_starts_with($path, 'phpstan')
        || str_starts_with($path, 'rector'),
    'зависимости и сборка' => static fn (string $path): bool => in_array(
        $path,
        ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'],
        true,
    ),
];

/** @var array<string, true> $relevantPaths */
$relevantPaths = [];
/** @var array<string, true> $matchedCategories */
$matchedCategories = [];

foreach (array_filter(explode("\0", $rawPaths), static fn (string $path): bool => $path !== '') as $path) {
    if (
        str_starts_with($path, '/')
        || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1
        || preg_match('~(?:^|/)\.\.(?:/|$)~', $path) === 1
    ) {
        fwrite(STDERR, "Автообновление CHANGELOG: staged-путь должен быть относительным и безопасным.\n");
        exit(2);
    }

    foreach ($categoryMatchers as $category => $matches) {
        if (! $matches($path)) {
            continue;
        }

        $relevantPaths[$path] = true;
        $matchedCategories[$category] = true;

        break;
    }
}

if ($relevantPaths === []) {
    exit(0);
}

$categories = array_values(array_filter(
    array_keys($categoryMatchers),
    static fn (string $category): bool => isset($matchedCategories[$category]),
));

$categorySummary = match (count($categories)) {
    1 => $categories[0],
    2 => implode(' и ', $categories),
    default => implode(', ', array_slice($categories, 0, -1)).' и '.$categories[array_key_last($categories)],
};

$entry = sprintf(
    '- Автоматически зафиксировано обновление кода. Области: %s. Количество изменённых файлов: %d.',
    $categorySummary,
    count($relevantPaths),
);

$contents = file_get_contents($changelogPath);

if ($contents === false) {
    fwrite(STDERR, "Автообновление CHANGELOG: не удалось прочитать файл.\n");
    exit(2);
}

if (str_contains($contents, $entry)) {
    exit(0);
}

$lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
$dateHeading = '## '.$date;
$datePattern = '/^('.preg_quote($dateHeading, '/').'\R)(?:\R)?/mu';
$updated = preg_replace(
    $datePattern,
    '$1'.$lineEnding.$entry.$lineEnding,
    $contents,
    1,
    $dateReplacements,
);

if ($updated === null) {
    fwrite(STDERR, "Автообновление CHANGELOG: не удалось обработать раздел даты.\n");
    exit(2);
}

if ($dateReplacements === 0) {
    $titlePattern = '/^(# Журнал изменений\R)(?:\R)?/u';
    $updated = preg_replace(
        $titlePattern,
        '$1'.$lineEnding.$dateHeading.$lineEnding.$lineEnding.$entry.$lineEnding.$lineEnding,
        $contents,
        1,
        $titleReplacements,
    );

    if ($updated === null || $titleReplacements !== 1) {
        fwrite(STDERR, "Автообновление CHANGELOG: не найден главный заголовок журнала.\n");
        exit(2);
    }
}

if (file_put_contents($changelogPath, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "Автообновление CHANGELOG: не удалось записать файл.\n");
    exit(2);
}
