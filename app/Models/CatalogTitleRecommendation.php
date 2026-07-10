<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_id',
    'recommended_title_id',
    'score',
    'rank',
    'algorithm_version',
    'matched_features_count',
    'metadata_score',
    'source_score',
    'quality_score',
    'reasons',
    'computed_at',
])]
class CatalogTitleRecommendation extends Model
{
    protected $attributes = [
        'algorithm_version' => 'v1',
    ];

    /**
     * @var array<string, string>
     */
    private const REASON_LABELS = [
        'genre' => 'Жанр',
        'tag' => 'Теги',
        'director' => 'Режиссер',
        'actor' => 'Актеры',
        'network' => 'Канал',
        'studio' => 'Студия',
        'translation' => 'Перевод',
        'status' => 'Статус',
        'country' => 'Страна',
        'age_rating' => 'Возраст',
        'year' => 'Год',
        'rating' => 'Рейтинг',
        'reviews' => 'Отзывы',
        'published_media' => 'Видео',
        'source_signal' => 'Источник',
    ];

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function recommendedTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'recommended_title_id');
    }

    /**
     * @return list<string>
     */
    public function reasonLabels(): array
    {
        $reasons = is_array($this->reasons) ? $this->reasons : [];

        return collect($reasons)
            ->keys()
            ->filter(fn (string $reason): bool => $reason !== 'type')
            ->map(fn (string $reason): ?string => self::REASON_LABELS[$reason] ?? null)
            ->filter()
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * @return array{metadata: int, source: int, quality: int, total: int}
     */
    public function scoreBreakdown(): array
    {
        return [
            'metadata' => (int) $this->metadata_score,
            'source' => (int) $this->source_score,
            'quality' => (int) $this->quality_score,
            'total' => (int) $this->score,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'rank' => 'integer',
            'matched_features_count' => 'integer',
            'metadata_score' => 'integer',
            'source_score' => 'integer',
            'quality_score' => 'integer',
            'reasons' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
