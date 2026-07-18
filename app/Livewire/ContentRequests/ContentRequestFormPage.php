<?php

declare(strict_types=1);

namespace App\Livewire\ContentRequests;

use App\Actions\ContentRequests\CreateContentRequest;
use App\Enums\ContentRequestExternalProvider;
use App\Enums\ContentRequestType;
use App\Enums\HelpFeature;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestInputFactory;
use App\Services\ContentRequests\ContentRequestQuery;
use App\Services\ContentRequests\ContentRequestSchema;
use App\Services\HelpCenter\HelpContextualLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

final class ContentRequestFormPage extends Component
{
    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    public string $type = 'serial';

    public string $title = '';

    public string $originalTitle = '';

    public string $alternativeTitle = '';

    public string $releaseYear = '';

    public string $country = '';

    public string $contentLocale = '';

    public string $originalLanguage = '';

    public string $audioLanguage = '';

    public string $subtitleLanguage = '';

    public string $translationType = '';

    public string $translationStudio = '';

    public string $catalogTitleId = '';

    public string $seasonId = '';

    public string $episodeId = '';

    public string $seasonNumber = '';

    public string $seasonKind = 'regular';

    public string $episodeNumber = '';

    public string $episodeReleaseDate = '';

    public string $currentQuality = '';

    public string $requestedQuality = '';

    public string $correctionField = '';

    public string $currentValue = '';

    public string $proposedValue = '';

    public string $explanation = '';

    public string $differentExplanation = '';

    /** @var list<array{provider: string, identifier: string}> */
    public array $externalIdentifiers = [['provider' => 'imdb', 'identifier' => '']];

    /** @var list<string> */
    public array $sourceLinks = [''];

    #[Locked]
    public string $submissionToken = '';

    public ?string $actionError = null;

    public ?string $canonicalUrl = null;

    public bool $searchFailed = false;

    public function mount(?string $locale = null): void
    {
        Gate::authorize('create', ContentRequest::class);
        $this->submissionToken = (string) Str::uuid();
        $requestedType = request()->query('type');
        $this->type = ContentRequestType::tryFrom(is_string($requestedType) ? $requestedType : '')?->value ?? ContentRequestType::Serial->value;
        $titleId = filter_var(request()->query('catalog_title_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($titleId !== false) {
            $this->selectCatalogTitle((int) $titleId);
        }
    }

    public function updatedType(): void
    {
        $this->type = ContentRequestType::tryFrom($this->type)?->value ?? ContentRequestType::Serial->value;
        $type = ContentRequestType::from($this->type);

        if (in_array($type, [ContentRequestType::Serial, ContentRequestType::Other], true)) {
            $this->clearCatalogTitle();
        } elseif ($type === ContentRequestType::Season) {
            $this->seasonId = '';
            $this->episodeId = '';
        } elseif ($type === ContentRequestType::Episode) {
            $this->episodeId = '';
        }

        if ($type !== ContentRequestType::Translation) {
            $this->translationType = '';
        }

        if ($type !== ContentRequestType::QualityUpgrade) {
            $this->currentQuality = '';
            $this->requestedQuality = '';
        }

        if (! in_array($type, [ContentRequestType::MetadataCorrection, ContentRequestType::EpisodeListCorrection], true)) {
            $this->correctionField = '';
            $this->currentValue = '';
            $this->proposedValue = '';
        }

        $this->actionError = null;
    }

    public function updatedSearch(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 120, '');
    }

    public function updatedSeasonId(): void
    {
        $this->episodeId = '';
    }

    public function selectCatalogTitle(int $id): void
    {
        $title = CatalogTitle::query()->availableTo(auth()->user())->findOrFail($id, ['id', 'title', 'original_title', 'year']);
        $this->catalogTitleId = (string) $title->id;
        $this->title = $title->display_title;
        $this->originalTitle = (string) $title->original_title;
        $this->releaseYear = $title->year !== null ? (string) $title->year : '';
        $this->search = $title->display_title;
        $this->seasonId = '';
        $this->episodeId = '';
        $this->actionError = null;
    }

    public function clearCatalogTitle(): void
    {
        $this->catalogTitleId = '';
        $this->seasonId = '';
        $this->episodeId = '';
    }

    public function addSourceLink(): void
    {
        if (count($this->sourceLinks) < max(1, (int) config('content-requests.max_source_links', 3))) {
            $this->sourceLinks[] = '';
        }
    }

    public function removeSourceLink(int $index): void
    {
        unset($this->sourceLinks[$index]);
        $this->sourceLinks = array_values($this->sourceLinks) ?: [''];
    }

    public function addExternalIdentifier(): void
    {
        if (count($this->externalIdentifiers) < max(1, (int) config('content-requests.max_external_ids', 5))) {
            $this->externalIdentifiers[] = ['provider' => 'imdb', 'identifier' => ''];
        }
    }

    public function removeExternalIdentifier(int $index): void
    {
        unset($this->externalIdentifiers[$index]);
        $this->externalIdentifiers = array_values($this->externalIdentifiers) ?: [['provider' => 'imdb', 'identifier' => '']];
    }

    public function submit(ContentRequestInputFactory $factory, CreateContentRequest $action): mixed
    {
        $this->validate();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $request = $action->handle($user, $factory->from($this->payload()));
            $locale = request()->route('locale');

            return $this->redirectRoute(
                is_string($locale) ? 'localized.requests.show' : 'requests.show',
                is_string($locale) ? ['locale' => $locale, 'contentRequest' => $request] : ['contentRequest' => $request],
                navigate: true,
            );
        } catch (ContentRequestActionException $exception) {
            $this->actionError = __($exception->translationKey, $exception->replace);
            $this->canonicalUrl = $exception->canonicalUrl;

            return null;
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('requests.errors.action_failed');

            return null;
        }
    }

    public function render(
        ContentRequestQuery $query,
        ContentRequestSchema $schema,
        HelpContextualLinkService $helpLinks,
    ): View {
        $suggestions = [];
        $this->searchFailed = false;

        if ($schema->ready() && mb_strlen($this->search) >= 2) {
            try {
                $suggestions = $query->autocomplete($this->search);
            } catch (Throwable $exception) {
                report($exception);
                $this->searchFailed = true;
            }
        }

        $seasons = $this->catalogTitleId !== ''
            ? Season::query()->availableTo(auth()->user())->where('catalog_title_id', (int) $this->catalogTitleId)->orderBy('kind')->orderBy('number')->get(['id', 'number', 'kind', 'title'])
            : collect();
        $episodes = $this->seasonId !== ''
            ? Episode::query()->availableTo(auth()->user())->where('season_id', (int) $this->seasonId)->orderBy('kind')->orderBy('number')->get(['id', 'number', 'kind', 'title'])
            : collect();

        return view('livewire.content-requests.form-page', [
            'schemaReady' => $schema->ready(),
            'suggestions' => $suggestions,
            'searchPerformed' => mb_strlen($this->search) >= 2 || $this->catalogTitleId !== '',
            'seasons' => $seasons,
            'episodes' => $episodes,
            'typeOptions' => collect(ContentRequestType::cases())->map(fn (ContentRequestType $type): array => ['value' => $type->value, 'label' => $type->label(), 'description' => $type->description()])->all(),
            'providerOptions' => collect(ContentRequestExternalProvider::cases())->map(fn (ContentRequestExternalProvider $provider): array => ['value' => $provider->value, 'label' => $provider->label()])->all(),
            'languageOptions' => collect((array) config('content-requests.language_codes', []))->map(fn (string $code): array => ['value' => $code, 'label' => __('requests.languages.'.$code)])->all(),
            'translationTypeOptions' => collect((array) config('content-requests.translation_types', []))->map(fn (string $value): array => ['value' => $value, 'label' => __('requests.translation_types.'.$value)])->all(),
            'qualityOptions' => (array) config('playback.supported_qualities', []),
            'correctionOptions' => collect((array) config('content-requests.correction_fields', []))->map(fn (string $value): array => ['value' => $value, 'label' => __('requests.correction_fields.'.$value)])->all(),
            'helpArticle' => $helpLinks->primary(
                HelpFeature::Requests,
                'form',
                app()->getLocale(),
                is_string(request()->route('locale')) ? request()->route('locale') : null,
            ),
        ])->extends('layouts.app', [
            'title' => __('requests.form.title'),
            'seo' => ['title' => __('requests.form.title'), 'description' => __('requests.form.description'), 'robots' => 'noindex, nofollow', 'canonical' => route('requests.create')],
        ])->section('content');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'type' => ['required', 'string'], 'title' => ['required', 'string', 'min:2', 'max:240'],
            'originalTitle' => ['nullable', 'string', 'max:240'], 'alternativeTitle' => ['nullable', 'string', 'max:240'],
            'releaseYear' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 3)],
            'country' => ['nullable', 'string', 'max:100'], 'contentLocale' => ['nullable', 'string', 'max:16'],
            'originalLanguage' => ['nullable', 'string', 'max:16'], 'audioLanguage' => ['nullable', 'string', 'max:16'],
            'subtitleLanguage' => ['nullable', 'string', 'max:16'], 'translationType' => ['nullable', 'string', 'max:32'],
            'translationStudio' => ['nullable', 'string', 'max:120'], 'catalogTitleId' => ['nullable', 'integer', 'min:1'],
            'seasonId' => ['nullable', 'integer', 'min:1'], 'episodeId' => ['nullable', 'integer', 'min:1'],
            'seasonNumber' => ['nullable', 'integer', 'min:0', 'max:999'], 'episodeNumber' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'episodeReleaseDate' => ['nullable', 'date_format:Y-m-d'], 'currentQuality' => ['nullable', 'string', 'max:16'],
            'requestedQuality' => ['nullable', 'string', 'max:16'], 'correctionField' => ['nullable', 'string', 'max:48'],
            'currentValue' => ['nullable', 'string', 'max:2000'], 'proposedValue' => ['nullable', 'string', 'max:4000'],
            'explanation' => ['nullable', 'string', 'max:4000'], 'differentExplanation' => ['nullable', 'string', 'max:1000'],
            'sourceLinks' => ['array', 'max:3'], 'sourceLinks.*' => ['nullable', 'string', 'max:2048'],
            'externalIdentifiers' => ['array', 'max:5'], 'externalIdentifiers.*.provider' => ['required', 'string'],
            'externalIdentifiers.*.identifier' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'type' => $this->type, 'title' => $this->title, 'original_title' => $this->originalTitle,
            'alternative_title' => $this->alternativeTitle, 'release_year' => $this->releaseYear, 'country' => $this->country,
            'content_locale' => $this->contentLocale, 'original_language' => $this->originalLanguage,
            'audio_language' => $this->audioLanguage, 'subtitle_language' => $this->subtitleLanguage,
            'translation_type' => $this->translationType, 'translation_studio' => $this->translationStudio,
            'catalog_title_id' => $this->catalogTitleId, 'season_id' => $this->seasonId, 'episode_id' => $this->episodeId,
            'season_number' => $this->seasonNumber, 'season_kind' => $this->seasonKind,
            'episode_number' => $this->episodeNumber, 'episode_release_date' => $this->episodeReleaseDate,
            'current_quality' => $this->currentQuality, 'requested_quality' => $this->requestedQuality,
            'correction_field' => $this->correctionField, 'current_value' => $this->currentValue,
            'proposed_value' => $this->proposedValue, 'explanation' => $this->explanation,
            'different_explanation' => $this->differentExplanation, 'source_links' => $this->sourceLinks,
            'external_identifiers' => $this->externalIdentifiers, 'submission_token' => $this->submissionToken,
        ];
    }
}
