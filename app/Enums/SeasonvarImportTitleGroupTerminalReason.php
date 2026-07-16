<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportTitleGroupTerminalReason: string
{
    case EmptyPageSet = 'empty_page_set';
    case PageSetMismatch = 'page_set_mismatch';
    case PreparationDeadlineExceeded = 'preparation_deadline_exceeded';
    case NoPreparedPages = 'no_prepared_pages';
    case FinalizerDeadlineExceeded = 'finalizer_deadline_exceeded';

    public function message(): string
    {
        return match ($this) {
            self::EmptyPageSet => 'Группа импорта не содержит страниц для обработки.',
            self::PageSetMismatch => 'Набор страниц группы импорта неполон.',
            self::PreparationDeadlineExceeded => 'Подготовка страницы не завершилась в допустимое время.',
            self::NoPreparedPages => 'Ни одна страница сезона не подготовлена.',
            self::FinalizerDeadlineExceeded => 'Группа сезонов не финализирована в допустимое время.',
        };
    }
}
