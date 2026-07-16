<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CatalogPublicationType;
use App\Enums\CatalogTitleRelationSource;
use App\Enums\CatalogTitleRelationType;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogAdministrationQuery;
use App\Services\Catalog\CatalogAdministrationService;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleRelationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogAdministrationManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $recommendationRelationSearch = '';

    /** @var array<string, mixed> */
    public array $titleForm = [];

    /** @var array{actor: string, director: string, genre: string, country: string, translation: string} */
    public array $relationSearch = [
        'actor' => '',
        'director' => '',
        'genre' => '',
        'country' => '',
        'translation' => '',
    ];

    /** @var array<string, mixed> */
    public array $lookupForm = [];

    /** @var array{type: string, priority: int|string, locked: bool} */
    public array $recommendationRelationForm = [
        'type' => 'companion',
        'priority' => 100,
        'locked' => true,
    ];

    /** @var array<string, mixed> */
    public array $seasonForm = [];

    /** @var array<string, mixed> */
    public array $episodeForm = [];

    /** @var array<string, mixed> */
    public array $mediaForm = [];

    #[Locked]
    public ?int $selectedTitleId = null;

    #[Locked]
    public string $titleVersion = '';

    #[Locked]
    public ?string $lookupType = null;

    #[Locked]
    public ?int $activeSeasonId = null;

    #[Locked]
    public ?int $editingSeasonId = null;

    #[Locked]
    public string $seasonVersion = '';

    #[Locked]
    public ?int $activeEpisodeId = null;

    #[Locked]
    public ?int $editingEpisodeId = null;

    #[Locked]
    public string $episodeVersion = '';

    #[Locked]
    public ?int $editingMediaId = null;

    #[Locked]
    public string $mediaVersion = '';

    public ?string $notice = null;

    protected CatalogAdministrationQuery $query;

    protected CatalogAdministrationService $administration;

    protected CatalogTaxonomyRegistry $taxonomies;

    protected CatalogTitleRelationService $titleRelations;

    public function boot(
        CatalogAdministrationQuery $query,
        CatalogAdministrationService $administration,
        CatalogTaxonomyRegistry $taxonomies,
        CatalogTitleRelationService $titleRelations,
    ): void {
        $this->query = $query;
        $this->administration = $administration;
        $this->taxonomies = $taxonomies;
        $this->titleRelations = $titleRelations;
    }

    public function mount(): void
    {
        Gate::authorize('manage-catalog');
    }

    public function updatedSearch(): void
    {
        $this->search = str($this->search)->squish()->limit(80, '')->toString();
        $this->resetPage(pageName: 'catalogAdminPage');
    }

    public function updatedRelationSearch(mixed $value, mixed $key): void
    {
        if (! is_string($key) || ! array_key_exists($key, $this->relationSearch)) {
            return;
        }

        $this->relationSearch[$key] = is_string($value)
            ? str($value)->squish()->limit(80, '')->toString()
            : '';
    }

    public function updatedRecommendationRelationSearch(): void
    {
        $this->recommendationRelationSearch = str($this->recommendationRelationSearch)->squish()->limit(80, '')->toString();
    }

    public function addRecommendationRelation(mixed $targetTitleId): void
    {
        $targetTitleId = $this->positiveId($targetTitleId);

        if ($targetTitleId === null) {
            return;
        }

        $validated = Validator::make(['relation' => $this->recommendationRelationForm], [
            'relation.type' => ['required', Rule::enum(CatalogTitleRelationType::class)],
            'relation.priority' => ['required', 'integer', 'between:0,65535'],
            'relation.locked' => ['required', 'boolean'],
        ])->validate()['relation'];
        $type = CatalogTitleRelationType::from((string) $validated['type']);
        $source = $this->selectedTitle();
        $target = $this->query->title($targetTitleId);
        $this->titleRelations->saveEditorial(
            $this->user(),
            $source,
            $target,
            $type,
            (int) $validated['priority'],
            (bool) $validated['locked'],
        );

        $this->recommendationRelationSearch = '';
        $this->notice = __('recommendations.admin.relation_saved');
        $this->resetErrorBag();
    }

    public function removeRecommendationRelation(mixed $relationId): void
    {
        $relationId = $this->positiveId($relationId);

        if ($relationId === null) {
            return;
        }

        $source = $this->selectedTitle();
        $relation = $this->query->recommendationRelation($source, $relationId);
        abort_unless($relation->relationSource() === CatalogTitleRelationSource::Editorial, 404);
        $this->titleRelations->removeEditorial($this->user(), $relation);

        $this->notice = __('recommendations.admin.relation_removed');
        $this->resetErrorBag();
    }

    public function selectTitle(mixed $titleId): void
    {
        $titleId = $this->positiveId($titleId);

        if ($titleId === null) {
            return;
        }

        $title = $this->query->title($titleId);
        Gate::authorize('viewAdmin', $title);
        $this->selectedTitleId = $title->id;
        $this->fillTitleForm($title);
        $this->resetHierarchyState();
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function saveTitle(): void
    {
        $user = $this->user();
        $title = $this->selectedTitle();
        $validated = Validator::make(
            ['titleForm' => $this->normalizedTitleInput()],
            $this->titleRules($title),
            $this->titleMessages(),
        )->validate()['titleForm'];
        $updated = $this->administration->updateTitle($user, $title, $validated, $this->titleVersion);

        $this->fillTitleForm($updated);
        $this->notice = 'Изменения сериала сохранены.';
        $this->resetErrorBag();
    }

    public function archiveTitle(): void
    {
        $user = $this->user();
        $title = $this->selectedTitle();
        $updated = $this->administration->archiveTitle($user, $title, $this->titleVersion);

        $this->fillTitleForm($updated);
        $this->notice = 'Сериал скрыт без удаления пользовательских данных.';
        $this->resetErrorBag();
    }

    public function attachRelation(mixed $type, mixed $relationId): void
    {
        if (! is_string($type) || ($relationId = $this->positiveId($relationId)) === null) {
            return;
        }

        $user = $this->user();
        $title = $this->selectedTitle();
        $updated = $this->administration->attachRelation(
            $user,
            $title,
            $type,
            $relationId,
            $this->titleVersion,
        );

        $this->fillTitleForm($updated);
        $this->notice = 'Связь каталога сохранена.';
        $this->resetErrorBag();
    }

    public function detachRelation(mixed $type, mixed $relationId): void
    {
        if (! is_string($type) || ($relationId = $this->positiveId($relationId)) === null) {
            return;
        }

        $user = $this->user();
        $title = $this->selectedTitle();
        $updated = $this->administration->detachRelation($user, $title, $type, $relationId, $this->titleVersion);

        $this->fillTitleForm($updated);
        $this->notice = 'Связь каталога удалена.';
        $this->resetErrorBag();
    }

    public function newLookup(mixed $type): void
    {
        if (! is_string($type) || ! in_array($type, $this->administration->editableRelations(), true)) {
            return;
        }

        $this->lookupType = $type;
        $this->lookupForm = ['name' => '', 'slug' => ''];
        $this->resetErrorBag('lookupForm');
    }

    public function saveLookup(): void
    {
        abort_if($this->lookupType === null, 404);
        $title = $this->selectedTitle();
        $user = $this->user();
        $modelClass = $this->taxonomies->modelClass($this->lookupType);
        $table = (new $modelClass)->getTable();
        $input = [
            'name' => is_string($this->lookupForm['name'] ?? null) ? trim($this->lookupForm['name']) : null,
            'slug' => is_string($this->lookupForm['slug'] ?? null) ? trim($this->lookupForm['slug']) : null,
        ];
        $validated = Validator::make(['lookupForm' => $input], [
            'lookupForm.name' => ['required', 'string', 'max:255'],
            'lookupForm.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($table, 'slug')],
        ], [
            'lookupForm.name.required' => 'Укажите название справочника.',
            'lookupForm.slug.required' => 'Укажите slug справочника.',
            'lookupForm.slug.regex' => 'Slug справочника имеет неверный формат.',
            'lookupForm.slug.unique' => 'Такой slug справочника уже существует.',
        ])->validate()['lookupForm'];
        $updated = $this->administration->createLookup(
            $user,
            $title,
            $this->lookupType,
            $validated,
            $this->titleVersion,
        );

        $this->fillTitleForm($updated);
        $this->lookupForm = [];
        $this->lookupType = null;
        $this->notice = 'Значение справочника создано и добавлено к сериалу.';
        $this->resetErrorBag();
    }

    public function newSeason(): void
    {
        $this->selectedTitle();
        $this->editingSeasonId = null;
        $this->seasonVersion = '';
        $this->seasonForm = $this->defaultReleaseForm() + ['title' => ''];
        $this->resetErrorBag('seasonForm');
    }

    public function selectSeason(mixed $seasonId): void
    {
        $seasonId = $this->positiveId($seasonId);

        if ($seasonId === null) {
            return;
        }

        $title = $this->selectedTitle();
        $season = $this->query->season($title, $seasonId);
        $this->activeSeasonId = $season->id;
        $this->activeEpisodeId = null;
        $this->editingEpisodeId = null;
        $this->editingMediaId = null;
        $this->episodeForm = [];
        $this->mediaForm = [];
        $this->resetErrorBag();
    }

    public function editSeason(mixed $seasonId): void
    {
        $this->selectSeason($seasonId);

        if ($this->activeSeasonId === null) {
            return;
        }

        $season = $this->query->season($this->selectedTitle(), $this->activeSeasonId);
        $this->editingSeasonId = $season->id;
        $this->fillSeasonForm($season);
    }

    public function saveSeason(): void
    {
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->editingSeasonId !== null ? $this->query->season($title, $this->editingSeasonId) : null;
        $input = $this->normalizedReleaseInput($this->seasonForm, includeTitle: true);
        $validated = Validator::make(['seasonForm' => $input], $this->seasonRules($title, $season), $this->releaseMessages('seasonForm'))->validate()['seasonForm'];
        $saved = $this->administration->saveSeason($user, $title, $validated, $season, $this->seasonVersion);

        $this->activeSeasonId = $saved->id;
        $this->editingSeasonId = $saved->id;
        $this->fillSeasonForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Сезон сохранён.';
        $this->resetErrorBag();
    }

    public function archiveSeason(): void
    {
        abort_if($this->editingSeasonId === null, 404);
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->query->season($title, $this->editingSeasonId);
        $saved = $this->administration->archiveSeason($user, $title, $season, $this->seasonVersion);

        $this->fillSeasonForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Сезон скрыт без удаления серий и пользовательских данных.';
        $this->resetErrorBag();
    }

    public function newEpisode(): void
    {
        $this->activeSeason();
        $this->editingEpisodeId = null;
        $this->episodeVersion = '';
        $this->episodeForm = $this->defaultReleaseForm() + [
            'title' => '',
            'released_at' => '',
            'summary' => '',
        ];
        $this->editingMediaId = null;
        $this->mediaForm = [];
        $this->resetErrorBag('episodeForm');
    }

    public function selectEpisode(mixed $episodeId): void
    {
        $episodeId = $this->positiveId($episodeId);

        if ($episodeId === null) {
            return;
        }

        $title = $this->selectedTitle();
        $season = $this->activeSeason();
        $episode = $this->query->episode($title, $season, $episodeId);
        $this->activeEpisodeId = $episode->id;
        $this->editingMediaId = null;
        $this->mediaForm = [];
        $this->resetErrorBag();
    }

    public function editEpisode(mixed $episodeId): void
    {
        $episodeId = $this->positiveId($episodeId);

        if ($episodeId === null) {
            return;
        }

        $title = $this->selectedTitle();
        $episode = $this->query->episodeInTitle($title, $episodeId);
        $season = $this->query->season($title, (int) $episode->season_id);
        $episode = $this->query->episode($title, $season, $episodeId);
        $this->activeSeasonId = $season->id;
        $this->activeEpisodeId = $episode->id;
        $this->editingEpisodeId = $episode->id;
        $this->fillEpisodeForm($episode);
        $this->editingMediaId = null;
        $this->mediaForm = [];
        $this->resetErrorBag();
    }

    public function saveEpisode(): void
    {
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->activeSeason();
        $episode = $this->editingEpisodeId !== null
            ? $this->query->episode($title, $season, $this->editingEpisodeId)
            : null;
        $input = $this->normalizedReleaseInput($this->episodeForm, includeTitle: true) + [
            'released_at' => $this->nullableString($this->episodeForm['released_at'] ?? null),
            'summary' => $this->nullableString($this->episodeForm['summary'] ?? null),
        ];
        $validated = Validator::make(['episodeForm' => $input], $this->episodeRules($season, $episode), $this->releaseMessages('episodeForm'))->validate()['episodeForm'];
        $saved = $this->administration->saveEpisode($user, $title, $season, $validated, $episode, $this->episodeVersion);

        $this->activeEpisodeId = $saved->id;
        $this->editingEpisodeId = $saved->id;
        $this->fillEpisodeForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Серия сохранена.';
        $this->resetErrorBag();
    }

    public function archiveEpisode(): void
    {
        abort_if($this->editingEpisodeId === null, 404);
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->activeSeason();
        $episode = $this->query->episode($title, $season, $this->editingEpisodeId);
        $saved = $this->administration->archiveEpisode($user, $title, $season, $episode, $this->episodeVersion);

        $this->fillEpisodeForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Серия скрыта без удаления истории просмотра.';
        $this->resetErrorBag();
    }

    public function newMedia(): void
    {
        $this->activeEpisode();
        $this->editingMediaId = null;
        $this->mediaVersion = '';
        $this->mediaForm = [
            'title' => '',
            'playback_url' => '',
            'quality' => '',
            'format' => '',
            'translation_name' => '',
            'has_subtitles' => false,
            'duration_seconds' => null,
            'status' => 'draft',
            'audience' => ContentAudience::Public->value,
            'available_from' => '',
            'available_until' => '',
        ];
        $this->resetErrorBag('mediaForm');
    }

    public function editMedia(mixed $mediaId): void
    {
        $mediaId = $this->positiveId($mediaId);

        if ($mediaId === null) {
            return;
        }

        $title = $this->selectedTitle();
        $media = $this->query->mediaItemInTitle($title, $mediaId);
        $episode = $this->query->episodeInTitle($title, (int) $media->episode_id);
        $season = $this->query->season($title, (int) $episode->season_id);
        $episode = $this->query->episode($title, $season, $episode->id);
        $media = $this->query->mediaItem($title, $episode, $media->id);
        $this->activeSeasonId = $season->id;
        $this->activeEpisodeId = $episode->id;
        $this->editingMediaId = $media->id;
        $this->fillMediaForm($media);
        $this->resetErrorBag();
    }

    public function saveMedia(): void
    {
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->activeSeason();
        $episode = $this->activeEpisode();
        $media = $this->editingMediaId !== null
            ? $this->query->mediaItem($title, $episode, $this->editingMediaId)
            : null;
        $input = $this->normalizedMediaInput();
        $validated = Validator::make(['mediaForm' => $input], $this->mediaRules($title, $media), $this->mediaMessages())->validate()['mediaForm'];
        $saved = $this->administration->saveMedia($user, $title, $season, $episode, $validated, $media, $this->mediaVersion);

        $this->editingMediaId = $saved->id;
        $this->fillMediaForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Видеоисточник сохранён.';
        $this->resetErrorBag();
    }

    public function archiveMedia(): void
    {
        abort_if($this->editingMediaId === null, 404);
        $user = $this->user();
        $title = $this->selectedTitle();
        $season = $this->activeSeason();
        $episode = $this->activeEpisode();
        $media = $this->query->mediaItem($title, $episode, $this->editingMediaId);
        $saved = $this->administration->archiveMedia($user, $title, $season, $episode, $media, $this->mediaVersion);

        $this->fillMediaForm($saved);
        $this->refreshTitleVersion();
        $this->notice = 'Видеоисточник снят с публикации.';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        Gate::authorize('manage-catalog');

        $selectedTitle = $this->selectedTitleId !== null
            ? $this->query->title($this->selectedTitleId)
            : null;
        $seasons = $selectedTitle !== null ? $this->query->seasons($selectedTitle) : collect();
        $activeSeason = $this->activeSeasonId !== null ? $seasons->firstWhere('id', $this->activeSeasonId) : null;
        $episodes = $selectedTitle !== null && $activeSeason instanceof Season
            ? $this->query->episodes($selectedTitle, $activeSeason)
            : collect();
        $activeEpisode = $this->activeEpisodeId !== null ? $episodes->firstWhere('id', $this->activeEpisodeId) : null;
        $mediaItems = $selectedTitle !== null && $activeEpisode instanceof Episode
            ? $this->query->media($selectedTitle, $activeEpisode)
            : collect();

        return view('livewire.catalog-administration-manager', [
            'titles' => $this->query->titles($this->search),
            'selectedTitle' => $selectedTitle,
            'relationGroups' => $selectedTitle !== null ? $this->relationGroups($selectedTitle) : [],
            'recommendationRelations' => $selectedTitle !== null ? $this->query->recommendationRelations($selectedTitle) : collect(),
            'recommendationRelationCandidates' => $selectedTitle !== null
                ? $this->query->recommendationRelationCandidates($selectedTitle, $this->recommendationRelationSearch)
                : collect(),
            'recommendationRelationTypes' => CatalogTitleRelationType::cases(),
            'seasons' => $seasons,
            'activeSeason' => $activeSeason,
            'episodes' => $episodes,
            'activeEpisode' => $activeEpisode,
            'mediaItems' => $mediaItems,
            'publicationStatuses' => PublicationStatus::cases(),
            'audiences' => ContentAudience::cases(),
            'releaseKinds' => ReleaseKind::cases(),
            'mediaStatuses' => ['draft' => 'Черновик', 'published' => 'Опубликовано', 'unavailable' => 'Недоступно'],
            'publicationLabels' => ['draft' => 'Черновик', 'published' => 'Опубликовано', 'hidden' => 'Скрыто'],
            'audienceLabels' => ['public' => 'Все посетители', 'authenticated' => 'Только после входа'],
            'releaseKindLabels' => ['regular' => 'Обычный', 'special' => 'Спецвыпуск'],
            'maxCatalogYear' => now()->year + 5,
            'supportedQualities' => config('playback.supported_qualities'),
            'allowedFormats' => config('playback.allowed_formats'),
        ])
            ->extends('layouts.app', [
                'title' => 'Управление каталогом',
                'seo' => [
                    'title' => 'Управление каталогом',
                    'description' => 'Служебное управление каталогом.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('admin.catalog'),
                ],
            ])->section('content');
    }

    private function selectedTitle(): CatalogTitle
    {
        abort_if($this->selectedTitleId === null, 404);

        $title = $this->query->title($this->selectedTitleId);
        Gate::authorize('viewAdmin', $title);

        return $title;
    }

    private function fillTitleForm(CatalogTitle $title): void
    {
        $this->titleForm = [
            'title' => $title->title,
            'original_title' => $title->original_title ?? '',
            'slug' => $title->slug,
            'external_id' => $title->external_id ?? '',
            'type' => $title->type,
            'year' => $title->year,
            'description' => $title->description ?? '',
            'poster_url' => $title->poster_url ?? '',
            'publication_status' => $title->publication_status->value,
            'audience' => $title->audience->value,
            'available_from' => $title->available_from?->format('Y-m-d H:i') ?? '',
            'available_until' => $title->available_until?->format('Y-m-d H:i') ?? '',
        ];
        $this->titleVersion = $this->administration->titleVersion($title);
    }

    /** @return array<string, mixed> */
    private function normalizedTitleInput(): array
    {
        $input = $this->titleForm;

        foreach (['original_title', 'external_id', 'description', 'poster_url', 'available_from', 'available_until'] as $key) {
            $value = $input[$key] ?? null;
            $input[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        foreach (['title', 'slug', 'type', 'publication_status', 'audience'] as $key) {
            $input[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : $input[$key] ?? null;
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    private function titleRules(CatalogTitle $title): array
    {
        return [
            'titleForm.title' => ['required', 'string', 'max:255'],
            'titleForm.original_title' => ['nullable', 'string', 'max:255'],
            'titleForm.slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('catalog_titles', 'slug')->ignore($title->id),
                Rule::unique('catalog_title_slugs', 'slug')->where(fn ($query) => $query->where('catalog_title_id', '<>', $title->id)),
            ],
            'titleForm.external_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/', Rule::unique('catalog_titles', 'external_id')->where('source_id', $title->source_id)->ignore($title->id)],
            'titleForm.type' => ['required', 'string', Rule::enum(CatalogPublicationType::class)],
            'titleForm.year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 5)],
            'titleForm.description' => ['nullable', 'string', 'max:20000'],
            'titleForm.poster_url' => ['nullable', 'url:http,https', 'max:2048'],
            'titleForm.publication_status' => ['required', Rule::enum(PublicationStatus::class)],
            'titleForm.audience' => ['required', Rule::enum(ContentAudience::class)],
            'titleForm.available_from' => ['nullable', 'date_format:Y-m-d H:i'],
            'titleForm.available_until' => ['nullable', 'date_format:Y-m-d H:i', 'after:titleForm.available_from'],
        ];
    }

    /** @return array<string, string> */
    private function titleMessages(): array
    {
        return [
            'titleForm.title.required' => 'Укажите название сериала.',
            'titleForm.slug.required' => 'Укажите slug сериала.',
            'titleForm.slug.regex' => 'Slug может содержать только латинские буквы, цифры и дефисы.',
            'titleForm.slug.unique' => 'Такой slug уже используется.',
            'titleForm.external_id.regex' => 'Внешний ID содержит неподдерживаемые символы.',
            'titleForm.external_id.unique' => 'Такой внешний ID уже используется этим источником.',
            'titleForm.available_from.date_format' => 'Дата начала должна быть в формате UTC: ГГГГ-ММ-ДД ЧЧ:ММ.',
            'titleForm.available_until.date_format' => 'Дата окончания должна быть в формате UTC: ГГГГ-ММ-ДД ЧЧ:ММ.',
            'titleForm.available_until.after' => 'Дата окончания должна быть позже даты начала.',
        ];
    }

    private function resetHierarchyState(): void
    {
        $this->activeSeasonId = null;
        $this->editingSeasonId = null;
        $this->seasonVersion = '';
        $this->seasonForm = [];
        $this->activeEpisodeId = null;
        $this->editingEpisodeId = null;
        $this->episodeVersion = '';
        $this->episodeForm = [];
        $this->editingMediaId = null;
        $this->mediaVersion = '';
        $this->mediaForm = [];
        $this->lookupType = null;
        $this->lookupForm = [];
    }

    /** @return array<string, mixed> */
    private function defaultReleaseForm(): array
    {
        return [
            'number' => null,
            'kind' => ReleaseKind::Regular->value,
            'sort_order' => 0,
            'publication_status' => PublicationStatus::Draft->value,
            'audience' => ContentAudience::Public->value,
            'available_from' => '',
            'available_until' => '',
        ];
    }

    private function activeSeason(): Season
    {
        abort_if($this->activeSeasonId === null, 404);

        return $this->query->season($this->selectedTitle(), $this->activeSeasonId);
    }

    private function activeEpisode(): Episode
    {
        abort_if($this->activeEpisodeId === null, 404);

        return $this->query->episode($this->selectedTitle(), $this->activeSeason(), $this->activeEpisodeId);
    }

    private function fillSeasonForm(Season $season): void
    {
        $this->seasonForm = [
            'number' => $season->number,
            'kind' => $season->kind->value,
            'sort_order' => $season->sort_order,
            'title' => $season->title ?? '',
            'publication_status' => $season->publication_status->value,
            'audience' => $season->audience->value,
            'available_from' => $season->available_from?->format('Y-m-d H:i') ?? '',
            'available_until' => $season->available_until?->format('Y-m-d H:i') ?? '',
        ];
        $this->seasonVersion = $this->administration->seasonVersion($season);
    }

    private function fillEpisodeForm(Episode $episode): void
    {
        $this->episodeForm = [
            'number' => $episode->number,
            'kind' => $episode->kind->value,
            'sort_order' => $episode->sort_order,
            'title' => $episode->title ?? '',
            'released_at' => $episode->released_at?->format('Y-m-d') ?? '',
            'summary' => $episode->summary ?? '',
            'publication_status' => $episode->publication_status->value,
            'audience' => $episode->audience->value,
            'available_from' => $episode->available_from?->format('Y-m-d H:i') ?? '',
            'available_until' => $episode->available_until?->format('Y-m-d H:i') ?? '',
        ];
        $this->episodeVersion = $this->administration->episodeVersion($episode);
    }

    private function fillMediaForm(LicensedMedia $media): void
    {
        $this->mediaForm = [
            'title' => $media->title,
            'playback_url' => '',
            'quality' => $media->quality ?? '',
            'format' => $media->format ?? '',
            'translation_name' => $media->translation_name ?? '',
            'has_subtitles' => $media->has_subtitles,
            'duration_seconds' => $media->duration_seconds,
            'status' => $media->status,
            'audience' => $media->audience->value,
            'available_from' => $media->available_from?->format('Y-m-d H:i') ?? '',
            'available_until' => $media->available_until?->format('Y-m-d H:i') ?? '',
        ];
        $this->mediaVersion = $this->administration->mediaVersion($media);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function normalizedReleaseInput(array $form, bool $includeTitle): array
    {
        $input = [
            'number' => $form['number'] ?? null,
            'kind' => is_string($form['kind'] ?? null) ? trim($form['kind']) : null,
            'sort_order' => $form['sort_order'] ?? null,
            'publication_status' => is_string($form['publication_status'] ?? null) ? trim($form['publication_status']) : null,
            'audience' => is_string($form['audience'] ?? null) ? trim($form['audience']) : null,
            'available_from' => $this->nullableString($form['available_from'] ?? null),
            'available_until' => $this->nullableString($form['available_until'] ?? null),
        ];

        if ($includeTitle) {
            $input['title'] = $this->nullableString($form['title'] ?? null);
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    private function seasonRules(CatalogTitle $title, ?Season $season): array
    {
        $kind = is_string($this->seasonForm['kind'] ?? null) ? $this->seasonForm['kind'] : '';

        return [
            'seasonForm.number' => ['required', 'integer', 'between:0,10000', Rule::unique('seasons', 'number')->where(fn ($query) => $query->where('catalog_title_id', $title->id)->where('kind', $kind))->ignore($season?->id)],
            'seasonForm.kind' => ['required', Rule::enum(ReleaseKind::class)],
            'seasonForm.sort_order' => ['required', 'integer', 'between:0,1000000'],
            'seasonForm.title' => ['nullable', 'string', 'max:255'],
            'seasonForm.publication_status' => ['required', Rule::enum(PublicationStatus::class)],
            'seasonForm.audience' => ['required', Rule::enum(ContentAudience::class)],
            'seasonForm.available_from' => ['nullable', 'date_format:Y-m-d H:i'],
            'seasonForm.available_until' => ['nullable', 'date_format:Y-m-d H:i', 'after:seasonForm.available_from'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function episodeRules(Season $season, ?Episode $episode): array
    {
        $kind = is_string($this->episodeForm['kind'] ?? null) ? $this->episodeForm['kind'] : '';

        return [
            'episodeForm.number' => ['required', 'integer', 'between:0,100000', Rule::unique('episodes', 'number')->where(fn ($query) => $query->where('season_id', $season->id)->where('kind', $kind))->ignore($episode?->id)],
            'episodeForm.kind' => ['required', Rule::enum(ReleaseKind::class)],
            'episodeForm.sort_order' => ['required', 'integer', 'between:0,1000000'],
            'episodeForm.title' => ['nullable', 'string', 'max:255'],
            'episodeForm.released_at' => ['nullable', 'date_format:Y-m-d'],
            'episodeForm.summary' => ['nullable', 'string', 'max:20000'],
            'episodeForm.publication_status' => ['required', Rule::enum(PublicationStatus::class)],
            'episodeForm.audience' => ['required', Rule::enum(ContentAudience::class)],
            'episodeForm.available_from' => ['nullable', 'date_format:Y-m-d H:i'],
            'episodeForm.available_until' => ['nullable', 'date_format:Y-m-d H:i', 'after:episodeForm.available_from'],
        ];
    }

    /** @return array<string, string> */
    private function releaseMessages(string $form): array
    {
        return [
            $form.'.number.required' => 'Укажите номер.',
            $form.'.number.unique' => 'Запись с таким номером и типом уже существует.',
            $form.'.available_from.date_format' => 'Дата начала должна быть в формате UTC: ГГГГ-ММ-ДД ЧЧ:ММ.',
            $form.'.available_until.date_format' => 'Дата окончания должна быть в формате UTC: ГГГГ-ММ-ДД ЧЧ:ММ.',
            $form.'.available_until.after' => 'Дата окончания должна быть позже даты начала.',
        ];
    }

    /** @return array<string, mixed> */
    private function normalizedMediaInput(): array
    {
        return [
            'title' => $this->nullableString($this->mediaForm['title'] ?? null),
            'playback_url' => $this->nullableString($this->mediaForm['playback_url'] ?? null),
            'quality' => $this->nullableString($this->mediaForm['quality'] ?? null),
            'format' => $this->nullableString($this->mediaForm['format'] ?? null),
            'translation_name' => $this->nullableString($this->mediaForm['translation_name'] ?? null),
            'has_subtitles' => filter_var($this->mediaForm['has_subtitles'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'duration_seconds' => $this->mediaForm['duration_seconds'] ?? null,
            'status' => is_string($this->mediaForm['status'] ?? null) ? trim($this->mediaForm['status']) : null,
            'audience' => is_string($this->mediaForm['audience'] ?? null) ? trim($this->mediaForm['audience']) : null,
            'available_from' => $this->nullableString($this->mediaForm['available_from'] ?? null),
            'available_until' => $this->nullableString($this->mediaForm['available_until'] ?? null),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function mediaRules(CatalogTitle $title, ?LicensedMedia $media): array
    {
        return [
            'mediaForm.title' => ['required', 'string', 'max:255'],
            'mediaForm.playback_url' => [Rule::requiredIf($media === null), 'nullable', 'url:https', 'max:2048', Rule::unique('licensed_media', 'playback_url')->where('catalog_title_id', $title->id)->ignore($media?->id)],
            'mediaForm.quality' => ['nullable', 'string', Rule::in((array) config('playback.supported_qualities', []))],
            'mediaForm.format' => ['required', 'string', Rule::in((array) config('playback.allowed_formats', []))],
            'mediaForm.translation_name' => ['nullable', 'string', 'max:120'],
            'mediaForm.has_subtitles' => ['required', 'boolean'],
            'mediaForm.duration_seconds' => ['nullable', 'integer', 'between:1,604800'],
            'mediaForm.status' => ['required', Rule::in(['draft', 'published', 'unavailable'])],
            'mediaForm.audience' => ['required', Rule::enum(ContentAudience::class)],
            'mediaForm.available_from' => ['nullable', 'date_format:Y-m-d H:i'],
            'mediaForm.available_until' => ['nullable', 'date_format:Y-m-d H:i', 'after:mediaForm.available_from'],
        ];
    }

    /** @return array<string, string> */
    private function mediaMessages(): array
    {
        return [
            'mediaForm.title.required' => 'Укажите название видеоисточника.',
            'mediaForm.playback_url.required' => 'Укажите HTTPS-ссылку видеоисточника.',
            'mediaForm.playback_url.url' => 'Видеоисточник должен использовать корректную HTTPS-ссылку.',
            'mediaForm.playback_url.unique' => 'Такой видеоисточник уже существует у сериала.',
            'mediaForm.format.required' => 'Укажите формат видео.',
            'mediaForm.format.in' => 'Выбран неподдерживаемый формат видео.',
            'mediaForm.quality.in' => 'Выбрано неподдерживаемое качество видео.',
            'mediaForm.available_until.after' => 'Дата окончания должна быть позже даты начала.',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function refreshTitleVersion(): void
    {
        $this->titleVersion = $this->administration->titleVersion($this->selectedTitle());
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     selected: Collection<int, Model>,
     *     options: Collection<int, Model>
     * }>
     */
    private function relationGroups(CatalogTitle $title): array
    {
        $labels = [
            'actor' => 'Актёры',
            'director' => 'Режиссёры',
            'genre' => 'Жанры',
            'country' => 'Страны',
            'translation' => 'Языки и переводы',
        ];

        return collect($this->administration->editableRelations())
            ->mapWithKeys(function (string $type) use ($title, $labels): array {
                $search = $this->relationSearch[$type] ?? '';
                $selected = $this->query->selectedRelations($title, $type);

                return [$type => [
                    'label' => $labels[$type],
                    'selected' => $selected,
                    'options' => mb_strlen($search) >= 2
                        ? $this->query->relationOptions($type, $search, $selected->pluck('id')->all())
                        : collect(),
                ]];
            })
            ->all();
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
