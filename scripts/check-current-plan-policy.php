<?php

declare(strict_types=1);

const CURRENT_PLAN_REQUIRED_SECTIONS = [
    'Реестр активных workstreams' => ['Workstream', true],
    'Реестр blocked/unresolved' => ['Workstream', false],
    'Task-specific compliance matrix' => ['Requirement', true],
    'Последнее подтверждённое evidence' => [null, false],
];

const CURRENT_PLAN_ALLOWED_STATUSES = [
    'planned',
    'in_progress',
    'completed',
    'already_compliant',
    'not_applicable',
    'unresolved',
];

function failCurrentPlanPolicy(string $message, ?int $lineNumber = null): never
{
    $location = $lineNumber === null ? '' : "строка {$lineNumber}: ";

    fwrite(STDERR, "Проверка current plan: {$location}{$message}\n");

    exit(1);
}

/**
 * @return array<int, string>
 */
function visibleMarkdownLines(string $contents): array
{
    $lines = preg_split('/\R/u', $contents);

    if ($lines === false) {
        failCurrentPlanPolicy('не удалось разобрать Markdown.');
    }

    $visibleLines = [];
    $fenceCharacter = null;
    $fenceLength = 0;

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trimmed = ltrim($line);

        if (preg_match('/^(`{3,}|~{3,})/', $trimmed, $matches) === 1) {
            $marker = $matches[1];
            $character = $marker[0];
            $length = strlen($marker);

            if ($fenceCharacter === null) {
                $fenceCharacter = $character;
                $fenceLength = $length;

                continue;
            }

            if ($character === $fenceCharacter && $length >= $fenceLength) {
                $fenceCharacter = null;
                $fenceLength = 0;
            }

            continue;
        }

        if ($fenceCharacter === null) {
            $visibleLines[$lineNumber] = $line;
        }
    }

    return $visibleLines;
}

/**
 * @param  array<int, string>  $visibleLines
 * @return array{array<int, array{line: int, title: string}>, array<int, array{line: int, title: string}>}
 */
function markdownHeadings(array $visibleLines): array
{
    $h1 = [];
    $h2 = [];

    foreach ($visibleLines as $lineNumber => $line) {
        if (preg_match('/^\s{0,3}#(?!#)\s+(.+?)\s*$/u', $line, $matches) === 1) {
            $h1[] = ['line' => $lineNumber, 'title' => trim($matches[1])];

            continue;
        }

        if (preg_match('/^\s{0,3}##(?!#)\s+(.+?)\s*$/u', $line, $matches) === 1) {
            $h2[] = ['line' => $lineNumber, 'title' => trim($matches[1])];
        }
    }

    return [$h1, $h2];
}

/**
 * @param  array<int, array{line: int, title: string}>  $h1
 */
function validateTopLevelHeading(array $h1): void
{
    $activeHeadings = array_values(array_filter(
        $h1,
        static fn (array $heading): bool => str_starts_with($heading['title'], 'Текущая задача'),
    ));

    if (count($activeHeadings) !== 1) {
        $lineNumber = $activeHeadings[1]['line'] ?? $h1[0]['line'] ?? null;

        failCurrentPlanPolicy('в документе должен быть ровно один H1, начинающийся с «Текущая задача».', $lineNumber);
    }

    foreach ($h1 as $heading) {
        if ($heading['line'] !== $activeHeadings[0]['line']) {
            failCurrentPlanPolicy('обнаружен посторонний H1; исторические и параллельные тела должны находиться в archive.', $heading['line']);
        }
    }
}

/**
 * @param  array<int, array{line: int, title: string}>  $h2
 * @return array<string, array{start: int, end: int}>
 */
function requiredSectionRanges(array $h2, int $lastLine): array
{
    $ranges = [];

    foreach (CURRENT_PLAN_REQUIRED_SECTIONS as $requiredTitle => $_configuration) {
        $matches = array_values(array_filter(
            $h2,
            static fn (array $heading): bool => $heading['title'] === $requiredTitle,
        ));

        if (count($matches) !== 1) {
            $lineNumber = $matches[1]['line'] ?? $matches[0]['line'] ?? null;

            failCurrentPlanPolicy(
                "раздел «{$requiredTitle}» должен встречаться ровно один раз.",
                $lineNumber,
            );
        }

        $start = $matches[0]['line'];
        $end = $lastLine;

        foreach ($h2 as $heading) {
            if ($heading['line'] > $start) {
                $end = $heading['line'] - 1;

                break;
            }
        }

        $ranges[$requiredTitle] = ['start' => $start, 'end' => $end];
    }

    return $ranges;
}

/**
 * @return array<int, string>|null
 */
function markdownTableCells(string $line): ?array
{
    $trimmed = trim($line);

    if (! str_starts_with($trimmed, '|') || ! str_ends_with($trimmed, '|')) {
        return null;
    }

    $trimmed = substr($trimmed, 1, -1);

    return array_map('trim', explode('|', $trimmed));
}

/**
 * @param  array<int, string>  $visibleLines
 * @return array<int, array{line: int, cells: array<int, string>}>
 */
function tableRowsForSection(
    array $visibleLines,
    int $start,
    int $end,
    string $firstColumn,
    string $sectionTitle,
): array {
    $sectionLines = array_filter(
        $visibleLines,
        static fn (int $lineNumber): bool => $lineNumber > $start && $lineNumber <= $end,
        ARRAY_FILTER_USE_KEY,
    );

    $headerLine = null;
    $headerCells = null;

    foreach ($sectionLines as $lineNumber => $line) {
        $cells = markdownTableCells($line);

        if ($cells === [$firstColumn, 'Status', 'Evidence']) {
            if ($headerLine !== null) {
                failCurrentPlanPolicy("раздел «{$sectionTitle}» содержит несколько registry tables.", $lineNumber);
            }

            $headerLine = $lineNumber;
            $headerCells = $cells;
        }
    }

    if ($headerLine === null || $headerCells === null) {
        failCurrentPlanPolicy(
            "раздел «{$sectionTitle}» должен содержать table {$firstColumn} | Status | Evidence.",
            $start,
        );
    }

    $separatorLine = null;

    foreach ($sectionLines as $lineNumber => $line) {
        if ($lineNumber <= $headerLine || trim($line) === '') {
            continue;
        }

        $cells = markdownTableCells($line);

        if (
            $cells === null
            || count($cells) !== 3
            || array_any($cells, static fn (string $cell): bool => preg_match('/^:?-{3,}:?$/', $cell) !== 1)
        ) {
            failCurrentPlanPolicy("раздел «{$sectionTitle}» содержит некорректный separator table.", $lineNumber);
        }

        $separatorLine = $lineNumber;

        break;
    }

    if ($separatorLine === null) {
        failCurrentPlanPolicy("раздел «{$sectionTitle}» не содержит separator table.", $headerLine);
    }

    $rows = [];

    foreach ($sectionLines as $lineNumber => $line) {
        if ($lineNumber <= $separatorLine) {
            continue;
        }

        if (trim($line) === '') {
            break;
        }

        $cells = markdownTableCells($line);

        if ($cells === null || count($cells) !== 3) {
            failCurrentPlanPolicy("раздел «{$sectionTitle}» содержит некорректную строку registry table.", $lineNumber);
        }

        $rows[] = ['line' => $lineNumber, 'cells' => $cells];
    }

    return $rows;
}

function normalizedMachineStatus(string $value): ?string
{
    $status = trim($value);

    if (str_starts_with($status, '`') && str_ends_with($status, '`') && strlen($status) >= 2) {
        $status = trim(substr($status, 1, -1));
    }

    $allowed = implode('|', array_map(
        static fn (string $candidate): string => preg_quote($candidate, '/'),
        CURRENT_PLAN_ALLOWED_STATUSES,
    ));

    if (preg_match("/^(?:{$allowed})(?::\\s*\\S(?:.*\\S)?)?$/u", $status) !== 1) {
        return null;
    }

    return $status;
}

function hasEvidence(string $value): bool
{
    $plain = preg_replace(
        [
            '/\[([^\]]+)\]\([^)]*\)/u',
            '/[`*_~<>]/u',
            '/&nbsp;/iu',
            '/\s+/u',
        ],
        ['$1', '', '', ''],
        trim($value),
    );

    return is_string($plain) && $plain !== '' && ! in_array($plain, ['-', '—'], true);
}

/**
 * @param  array<int, array{line: int, cells: array<int, string>}>  $rows
 */
function validateRegistryRows(array $rows, string $sectionTitle, bool $requiresRows): void
{
    if ($requiresRows && $rows === []) {
        failCurrentPlanPolicy("раздел «{$sectionTitle}» должен содержать хотя бы одну строку evidence.");
    }

    foreach ($rows as $row) {
        if (normalizedMachineStatus($row['cells'][1]) === null) {
            failCurrentPlanPolicy('registry row содержит неподдерживаемый status.', $row['line']);
        }

        if (! hasEvidence($row['cells'][2])) {
            failCurrentPlanPolicy('registry row требует непустое evidence.', $row['line']);
        }
    }
}

/**
 * @param  array<int, string>  $segments
 */
function normalizedArchiveRelativePath(array $segments, int $lineNumber): string
{
    $normalized = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            failCurrentPlanPolicy('архивная ссылка выходит за каталог archive.', $lineNumber);
        }

        $normalized[] = $segment;
    }

    if ($normalized === []) {
        failCurrentPlanPolicy('архивная ссылка не указывает Markdown-файл.', $lineNumber);
    }

    return implode(DIRECTORY_SEPARATOR, $normalized);
}

/**
 * @param  array<int, string>  $visibleLines
 * @param  array{start: int, end: int}  $latestEvidenceRange
 */
function validateArchiveLinks(array $visibleLines, string $planPath, array $latestEvidenceRange): void
{
    $planDirectory = dirname($planPath);
    $archiveDirectory = $planDirectory.DIRECTORY_SEPARATOR.'archive';
    $validLatestEvidenceLinks = 0;

    foreach ($visibleLines as $lineNumber => $line) {
        $linkSource = preg_replace('/`[^`\r\n]*`/u', '', $line) ?? $line;

        preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+["\'][^)]*["\'])?\)/u', $linkSource, $matches);

        foreach ($matches[1] as $target) {
            $decodedTarget = rawurldecode($target);

            if (preg_match('/[\x00-\x1F\x7F]/', $decodedTarget) === 1) {
                failCurrentPlanPolicy('архивная ссылка содержит недопустимые символы.', $lineNumber);
            }

            $pathOnly = preg_split('/[?#]/', $decodedTarget, 2)[0] ?? '';
            $portablePath = str_replace('\\', '/', $pathOnly);
            $mentionsArchive = preg_match('~(?:^|/)archive/~', $portablePath) === 1;

            if (! str_starts_with($portablePath, 'archive/')) {
                if ($mentionsArchive) {
                    failCurrentPlanPolicy(
                        'архивная ссылка должна быть относительной и начинаться с archive/.',
                        $lineNumber,
                    );
                }

                continue;
            }

            $relativePath = normalizedArchiveRelativePath(
                explode('/', substr($portablePath, strlen('archive/'))),
                $lineNumber,
            );

            if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'md') {
                failCurrentPlanPolicy('архивная ссылка должна указывать Markdown-файл.', $lineNumber);
            }

            $candidate = $archiveDirectory.DIRECTORY_SEPARATOR.$relativePath;

            if (! is_file($candidate)) {
                failCurrentPlanPolicy('архивный файл не найден.', $lineNumber);
            }

            $resolvedArchive = realpath($archiveDirectory);
            $resolvedCandidate = realpath($candidate);

            if (
                $resolvedArchive === false
                || $resolvedCandidate === false
                || ! str_starts_with($resolvedCandidate, $resolvedArchive.DIRECTORY_SEPARATOR)
            ) {
                failCurrentPlanPolicy('архивная ссылка выходит за каталог archive.', $lineNumber);
            }

            if ($lineNumber > $latestEvidenceRange['start'] && $lineNumber <= $latestEvidenceRange['end']) {
                $validLatestEvidenceLinks++;
            }
        }
    }

    if ($validLatestEvidenceLinks === 0) {
        failCurrentPlanPolicy(
            'раздел «Последнее подтверждённое evidence» требует относительную ссылку на archive.',
            $latestEvidenceRange['start'],
        );
    }
}

$path = $argv[1] ?? 'docs/plans/current-task-plan.md';

if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
    failCurrentPlanPolicy('Markdown-файл не найден или недоступен для чтения.');
}

$contents = file_get_contents($path);

if ($contents === false) {
    failCurrentPlanPolicy('не удалось прочитать Markdown-файл.');
}

if (preg_match('//u', $contents) !== 1) {
    failCurrentPlanPolicy('Markdown-файл должен быть корректным UTF-8.');
}

$visibleLines = visibleMarkdownLines($contents);
[$h1, $h2] = markdownHeadings($visibleLines);

validateTopLevelHeading($h1);

$lastLine = $visibleLines === [] ? 0 : max(array_keys($visibleLines));
$sectionRanges = requiredSectionRanges($h2, $lastLine);

foreach (CURRENT_PLAN_REQUIRED_SECTIONS as $sectionTitle => [$firstColumn, $requiresRows]) {
    if ($firstColumn === null) {
        continue;
    }

    $range = $sectionRanges[$sectionTitle];
    $rows = tableRowsForSection(
        $visibleLines,
        $range['start'],
        $range['end'],
        $firstColumn,
        $sectionTitle,
    );

    validateRegistryRows($rows, $sectionTitle, $requiresRows);
}

validateArchiveLinks(
    $visibleLines,
    $path,
    $sectionRanges['Последнее подтверждённое evidence'],
);
