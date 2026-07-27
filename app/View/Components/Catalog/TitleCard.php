<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use App\Enums\CatalogRecommendationFeedbackReason;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Season;
use App\Support\PlainText;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\View\Component;

class TitleCard extends Component
{
    private const DESCRIPTION_LIMIT = 240;

    private const REASON_LIMIT = 120;

    private const LAYOUTS = ['grid', 'list', 'compact', 'recommendation', 'home', 'spotlight', 'trend'];

    public int $seasonsCount;

    public int $episodesCount;

    public int $mediaCount;

    public string $displayTitle;

    public string $seasonsLabel;

    public string $episodesLabel;

    public string $mediaLabel;

    public ?string $descriptionExcerpt;

    public ?string $ratingLabel;

    /** @var list<string> */
    public array $ratingLabels;

    public ?string $countryName;

    /** @var list<string> */
    public array $listMetadata;

    public bool $isAdult;

    public bool $hasNewEpisode;

    public ?string $playActionUrl;

    public ?string $playActionLabel;

    /** @var list<string> */
    public array $feedbackReasons;

    /** @var list<string> */
    public array $recommendationReasons;

    /** @var list<string> */
    public array $recommendationMetadata;

    public ?string $primaryReason;

    public string $reasonHeading;

    public ?Season $latestSeason;

    public string $layout;

    public bool $hasPersonalState;

    public bool $userInWatchlist;

    public ?int $userRating;

    public ?int $userProgressPercent;

    /** @var array{type: string, label: string, url: string}|null */
    public ?array $userPrimaryAction;

    public ?string $translationPreferenceState;

    public ?string $translationPreferenceLabel;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardRelations;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardGenres;

    /**
     * @param  list<string>  $reasonLabels
     * @param  array{type: string, label: string, url: string}|null  $userPrimaryAction
     */
    public function __construct(
        public CatalogTitle $title,
        string $layout = 'list',
        public bool $showDescription = true,
        public bool $readable = false,
        public ?int $rank = null,
        public array $reasonLabels = [],
        ?string $reasonHeading = null,
        ?bool $userInWatchlist = null,
        ?int $userRating = null,
        ?int $userProgressPercent = null,
        ?array $userPrimaryAction = null,
        public ?string $preferredRatingProvider = null,
        public bool $interactive = false,
        public bool $viewerAuthenticated = false,
    ) {
        $this->layout = in_array($layout, self::LAYOUTS, true) ? $layout : 'list';
        $this->displayTitle = filled($title->display_title)
            ? (string) $title->display_title
            : __('catalog.title.untitled');
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->mediaCount = (int) ($title->published_media_count ?? $title->licensed_media_count ?? 0);
        $this->seasonsLabel = $this->countLabel('catalog.counts.seasons', $this->seasonsCount);
        $this->episodesLabel = $this->countLabel('catalog.counts.episodes', $this->episodesCount);
        $this->mediaLabel = $this->countLabel('catalog.counts.videos', $this->mediaCount);
        $descriptionLimit = $this->layout === 'recommendation' ? 180 : self::DESCRIPTION_LIMIT;
        $this->descriptionExcerpt = $showDescription && $title->hasAttribute('description')
            ? $this->boundedText($title->getAttribute('description'), $descriptionLimit)
            : null;
        $ratingLabels = $this->ratingLabels($title);
        $this->ratingLabels = collect(['imdb', 'kinopoisk'])
            ->map(fn (string $provider): ?string => $ratingLabels[$provider] ?? null)
            ->filter()
            ->values()
            ->all();
        $preferredRatingProviders = match ($this->preferredRatingProvider) {
            'imdb' => ['imdb', 'kinopoisk'],
            'kinopoisk' => ['kinopoisk', 'imdb'],
            default => in_array($this->layout, ['grid', 'recommendation'], true)
                ? ['imdb', 'kinopoisk']
                : ['kinopoisk', 'imdb'],
        };
        $this->ratingLabel = collect($preferredRatingProviders)
            ->map(fn (string $provider): ?string => $ratingLabels[$provider] ?? null)
            ->filter()
            ->first();
        $this->recommendationMetadata = collect([
            $title->year === null ? null : (string) $title->year,
            $this->seasonsCount > 0 ? $this->seasonsLabel : null,
            $this->ratingLabel,
        ])->filter()->values()->all();
        $this->recommendationReasons = collect($reasonLabels)
            ->map(fn (mixed $reason): ?string => $this->boundedText($reason, self::REASON_LIMIT))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
        $this->primaryReason = $this->recommendationReasons[0] ?? null;
        $this->reasonHeading = $this->boundedText(
            $reasonHeading ?? __('recommendations.page.why'),
            80,
        ) ?? __('recommendations.page.why');
        $this->latestSeason = $title->relationLoaded('latestSeason') ? $title->latestSeason : null;
        $this->countryName = $this->boundedText(
            $title->hasAttribute('card_country_name')
                ? $title->getAttribute('card_country_name')
                : null,
            80,
        );
        $this->listMetadata = collect([
            $title->year === null ? null : (string) $title->year,
            $this->countryName,
            $this->seasonsCount > 0 ? $this->seasonsLabel : null,
            $this->episodesCount > 0 ? $this->episodesLabel : null,
        ])->filter(fn (?string $value): bool => $value !== null)->values()->all();
        $this->isAdult = $title->hasAttribute('card_is_adult')
            && (bool) $title->getAttribute('card_is_adult');
        $this->hasNewEpisode = $title->hasAttribute('card_has_new_episode')
            && (bool) $title->getAttribute('card_has_new_episode');
        $this->userInWatchlist = $userInWatchlist
            ?? ($title->hasAttribute('user_in_watchlist') && (bool) $title->getAttribute('user_in_watchlist'));
        $this->userRating = $userRating ?? $this->integerAttribute($title, 'user_rating');
        $this->userProgressPercent = $userProgressPercent ?? $this->integerAttribute($title, 'user_progress_percent');
        $this->userPrimaryAction = $userPrimaryAction ?? $this->primaryActionAttribute($title);
        $translationPreferenceState = $title->hasAttribute('user_translation_preference_state')
            ? $title->getAttribute('user_translation_preference_state')
            : null;
        $this->translationPreferenceState = in_array($translationPreferenceState, ['preferred', 'alternative'], true)
            ? $translationPreferenceState
            : null;
        $this->translationPreferenceLabel = $this->translationPreferenceState !== null
            ? __('catalog.title.translation_preference.'.$this->translationPreferenceState)
            : null;
        $this->hasPersonalState = $this->userInWatchlist
            || $this->userRating !== null
            || $this->userProgressPercent !== null
            || $this->userPrimaryAction !== null
            || $this->translationPreferenceState !== null;
        $genreLimit = in_array($this->layout, ['grid', 'list', 'home', 'spotlight'], true) ? 2 : 3;
        $this->cardGenres = ($title->relationLoaded('genres') ? $title->genres : collect())->take($genreLimit);
        $this->cardRelations = $this->cardGenres;
        $this->playActionUrl = $this->userPrimaryAction['url']
            ?? ($this->mediaCount > 0 ? route('titles.show', $title).'#player' : null);
        $this->playActionLabel = $this->userPrimaryAction['label']
            ?? ($this->playActionUrl !== null ? __('catalog.title.card_actions.watch') : null);
        $this->feedbackReasons = collect(CatalogRecommendationFeedbackReason::cases())
            ->reject(fn (CatalogRecommendationFeedbackReason $reason): bool => $reason->requiresSubject())
            ->map(fn (CatalogRecommendationFeedbackReason $reason): string => $reason->value)
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view(match ($this->layout) {
            'grid' => 'components.catalog.title-card-grid',
            'recommendation' => 'components.catalog.title-card-recommendation',
            'home', 'spotlight' => 'components.catalog.title-card-home',
            'trend' => 'components.catalog.title-card-trend',
            'compact' => 'components.catalog.title-card-compact',
            default => 'components.catalog.title-card-list',
        });
    }

    private function countLabel(string $key, int $count): string
    {
        return trans_choice($key, $count, [
            'count' => Number::format($count, locale: app()->currentLocale()),
        ]);
    }

    private function integerAttribute(CatalogTitle $title, string $key): ?int
    {
        if (! $title->hasAttribute($key)) {
            return null;
        }

        $value = $title->getAttribute($key);

        return $value === null ? null : (int) $value;
    }

    private function boundedText(mixed $value, int $limit): ?string
    {
        $text = PlainText::clean($value);

        if ($text === '') {
            return null;
        }

        if (Str::length($text) <= $limit) {
            return $text;
        }

        return Str::limit($text, $limit - 1, '…', preserveWords: true);
    }

    /** @return array<string, string> */
    private function ratingLabels(CatalogTitle $title): array
    {
        $ratings = [];

        foreach (['imdb', 'kinopoisk'] as $provider) {
            $attribute = 'card_'.$provider.'_rating';

            if ($title->hasAttribute($attribute) && is_numeric($title->getAttribute($attribute))) {
                $ratings[$provider] = (float) $title->getAttribute($attribute);
            }
        }

        if ($title->relationLoaded('ratings')) {
            $title->ratings
                ->filter(
                    fn (CatalogTitleRating $rating): bool => in_array($rating->provider, ['imdb', 'kinopoisk'], true)
                        && $rating->rating !== null,
                )
                ->each(function (CatalogTitleRating $rating) use (&$ratings): void {
                    $ratings[$rating->provider] ??= (float) $rating->rating;
                });
        }

        return collect($ratings)
            ->mapWithKeys(fn (float $rating, string $provider): array => [
                $provider => __('catalog.title.card_rating', [
                    'provider' => __("catalog.title.rating_providers.{$provider}"),
                    'rating' => Number::format(
                        $rating,
                        precision: 1,
                        locale: app()->currentLocale(),
                    ),
                ]),
            ])
            ->all();
    }

    /** @return array{type: string, label: string, url: string}|null */
    private function primaryActionAttribute(CatalogTitle $title): ?array
    {
        if (! $title->hasAttribute('user_primary_action')) {
            return null;
        }

        $action = $title->getAttribute('user_primary_action');

        if (! is_array($action)
            || ! is_string($action['type'] ?? null)
            || ! is_string($action['label'] ?? null)
            || ! is_string($action['url'] ?? null)) {
            return null;
        }

        return [
            'type' => $action['type'],
            'label' => $action['label'],
            'url' => $action['url'],
        ];
    }
}
