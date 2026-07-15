<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class TitleCard extends Component
{
    private const LAYOUTS = ['list', 'compact', 'recommendation'];

    public int $seasonsCount;

    public int $episodesCount;

    public int $mediaCount;

    public ?Season $latestSeason;

    public string $layout;

    public bool $hasPersonalState;

    public bool $userInWatchlist;

    public ?int $userRating;

    public ?int $userProgressPercent;

    /** @var array{type: string, label: string, url: string}|null */
    public ?array $userPrimaryAction;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardRelations;

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
        ?bool $userInWatchlist = null,
        ?int $userRating = null,
        ?int $userProgressPercent = null,
        ?array $userPrimaryAction = null,
    ) {
        $this->layout = in_array($layout, self::LAYOUTS, true) ? $layout : 'list';
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->mediaCount = (int) ($title->published_media_count ?? $title->licensed_media_count ?? 0);
        $this->latestSeason = $title->relationLoaded('latestSeason') ? $title->latestSeason : null;
        $this->hasPersonalState = $userInWatchlist !== null || $title->hasAttribute('user_in_watchlist');
        $this->userInWatchlist = $userInWatchlist
            ?? ($title->hasAttribute('user_in_watchlist') && (bool) $title->getAttribute('user_in_watchlist'));
        $this->userRating = $userRating ?? $this->integerAttribute($title, 'user_rating');
        $this->userProgressPercent = $userProgressPercent ?? $this->integerAttribute($title, 'user_progress_percent');
        $this->userPrimaryAction = $userPrimaryAction ?? $this->primaryActionAttribute($title);
        $this->cardRelations = collect()
            ->merge($title->relationLoaded('genres') ? $title->genres : collect())
            ->merge($title->relationLoaded('countries') ? $title->countries : collect())
            ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
            ->merge($title->relationLoaded('translations') ? $title->translations : collect())
            ->merge($title->relationLoaded('tags') ? $title->tags : collect())
            ->take(4);
    }

    public function render(): View
    {
        return view(match ($this->layout) {
            'recommendation' => 'components.catalog.title-card-recommendation',
            default => 'components.catalog.title-card-list',
        });
    }

    private function integerAttribute(CatalogTitle $title, string $key): ?int
    {
        if (! $title->hasAttribute($key)) {
            return null;
        }

        $value = $title->getAttribute($key);

        return $value === null ? null : (int) $value;
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
