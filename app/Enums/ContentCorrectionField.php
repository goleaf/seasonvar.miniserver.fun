<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentCorrectionField: string
{
    case Title = 'title';
    case Year = 'year';
    case Genre = 'genre';
    case Tag = 'tag';
    case Country = 'country';
    case Actor = 'actor';
    case Poster = 'poster';
    case Description = 'description';
    case Translation = 'translation';
    case Episode = 'episode';
    case Subtitles = 'subtitles';

    public function requestType(): ContentRequestType
    {
        return $this === self::Episode
            ? ContentRequestType::EpisodeListCorrection
            : ContentRequestType::MetadataCorrection;
    }

    public function storedField(): string
    {
        return match ($this) {
            self::Actor => 'cast',
            self::Episode => 'episode_list',
            default => $this->value,
        };
    }

    public function relationName(): ?string
    {
        return match ($this) {
            self::Genre => 'genres',
            self::Tag => 'tags',
            self::Country => 'countries',
            self::Actor => 'actors',
            self::Translation => 'translations',
            default => null,
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::Tag;
    }

    public function label(): string
    {
        return __('requests.correction_fields.'.$this->storedField());
    }

    public static function fromStoredField(?string $field, ?string $targetKey = null): ?self
    {
        if ($targetKey !== null) {
            $prefix = strstr($targetKey, ':', true);
            $fromPrefix = is_string($prefix) ? self::tryFrom($prefix) : null;

            if ($fromPrefix !== null && $fromPrefix->storedField() === $field) {
                return $fromPrefix;
            }
        }

        return match ($field) {
            'cast' => self::Actor,
            'episode_list' => self::Episode,
            default => self::tryFrom((string) $field),
        };
    }
}
