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
     * @var array<string, string>
     */
    private const THEME_REASON_LABELS = [
        'theme_romance' => 'Романтика',
        'theme_relationships' => 'Отношения',
        'theme_friendship' => 'Дружба',
        'theme_youth' => 'Молодые герои',
        'theme_family' => 'Семья',
        'theme_workplace' => 'Работа',
        'theme_school' => 'Учёба',
        'theme_medical' => 'Медицина',
        'theme_legal' => 'Право',
        'theme_crime' => 'Преступление',
        'theme_mystery' => 'Тайна',
        'theme_fantasy' => 'Фэнтези',
        'theme_supernatural' => 'Сверхъестественное',
        'theme_science_fiction' => 'Фантастика',
        'theme_historical' => 'История',
        'theme_military' => 'Военная тема',
        'theme_adventure' => 'Приключения',
        'theme_sports' => 'Спорт',
        'theme_music' => 'Музыка',
        'theme_show_business' => 'Шоу-бизнес',
        'theme_everyday_life' => 'Повседневная жизнь',
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
            ->map(fn (mixed $details, string $reason): array => [
                'reason' => $reason,
                'score' => is_array($details) ? (int) ($details['score'] ?? 0) : 0,
            ])
            ->reject(fn (array $item): bool => $item['reason'] === 'type')
            ->sortByDesc('score')
            ->map(fn (array $item): ?string => self::THEME_REASON_LABELS[$item['reason']]
                ?? self::REASON_LABELS[$item['reason']]
                ?? null)
            ->filter()
            ->unique()
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
