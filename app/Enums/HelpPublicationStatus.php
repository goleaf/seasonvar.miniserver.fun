<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpPublicationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';
    case Hidden = 'hidden';

    public function label(): string
    {
        return __('help.statuses.'.$this->value);
    }

    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Hidden],
            self::InReview => [self::Draft, self::Approved],
            self::Approved => [self::Draft, self::Published],
            self::Published => [self::Published, self::Approved, self::Archived, self::Hidden],
            self::Archived => [self::Draft],
            self::Hidden => [self::Draft, self::Published],
        };
    }
}
