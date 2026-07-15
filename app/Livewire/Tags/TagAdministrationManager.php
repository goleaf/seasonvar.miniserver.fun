<?php

declare(strict_types=1);

namespace App\Livewire\Tags;

use App\DTOs\TagData;
use App\Enums\TagAliasSource;
use App\Enums\TagModerationStatus;
use App\Enums\TagProviderMappingStatus;
use App\Enums\TagSource;
use App\Enums\TagSynonymRelationship;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\TagSynonym;
use App\Models\User;
use App\Services\Tags\TagAdministrationQuery;
use App\Services\Tags\TagAssignmentService;
use App\Services\Tags\TagService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class TagAdministrationManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $moderationFilter = '';

    /** @var array<string, mixed> */
    public array $tagForm = [];

    /** @var array<string, array<string, string>> */
    public array $translationForms = [];

    public string $aliasName = '';

    public string $aliasLocale = 'und';

    public string $relationshipSearch = '';

    public ?string $relationshipTarget = null;

    public string $mergeSearch = '';

    public ?string $mergeTarget = null;

    public string $titleSearch = '';

    #[Locked]
    public ?string $selectedPublicId = null;

    #[Locked]
    public string $tagVersion = '';

    public ?string $notice = null;

    protected TagAdministrationQuery $query;

    protected TagService $tags;

    protected TagAssignmentService $assignments;

    public function boot(
        TagAdministrationQuery $query,
        TagService $tags,
        TagAssignmentService $assignments,
    ): void {
        $this->query = $query;
        $this->tags = $tags;
        $this->assignments = $assignments;
        Gate::authorize('manage-catalog');
    }

    public function mount(): void
    {
        $this->newTag();
    }

    public function updatedSearch(): void
    {
        $this->search = str($this->search)->squish()->limit(80, '')->toString();
        $this->resetPage(pageName: 'tagAdminPage');
    }

    public function updatedModerationFilter(): void
    {
        if ($this->moderationFilter !== '' && TagModerationStatus::tryFrom($this->moderationFilter) === null) {
            $this->moderationFilter = '';
        }

        $this->resetPage(pageName: 'tagAdminPage');
    }

    public function newTag(): void
    {
        $this->selectedPublicId = null;
        $this->tagVersion = '';
        $this->tagForm = [
            'name' => '',
            'code' => '',
            'slug' => '',
            'type' => TagType::Editorial->value,
            'visibility' => TagVisibility::Public->value,
            'moderation_status' => TagModerationStatus::Approved->value,
            'source' => TagSource::Editorial->value,
        ];
        $this->translationForms = $this->emptyTranslationForms();
        $this->resetSecondaryForms();
        $this->notice = null;
        $this->resetValidation();
    }

    public function selectTag(string $publicId): void
    {
        $tag = $this->query->tag($publicId);
        abort_if($tag === null, 404);
        $this->selectedPublicId = (string) $tag->public_id;
        $this->fillTagForm($tag);
        $this->resetSecondaryForms();
        $this->notice = null;
        $this->resetValidation();
    }

    public function saveTag(): void
    {
        $validated = Validator::make(['tagForm' => $this->tagForm], [
            'tagForm.name' => ['required', 'string', 'max:80'],
            'tagForm.code' => ['nullable', 'string', 'max:120'],
            'tagForm.slug' => ['nullable', 'string', 'max:180'],
            'tagForm.type' => ['required', Rule::enum(TagType::class)],
            'tagForm.visibility' => ['required', Rule::enum(TagVisibility::class)],
            'tagForm.moderation_status' => ['required', Rule::enum(TagModerationStatus::class)],
            'tagForm.source' => ['required', Rule::enum(TagSource::class)],
        ])->validate()['tagForm'];
        $data = new TagData(
            name: (string) $validated['name'],
            code: is_string($validated['code'] ?? null) ? $validated['code'] : null,
            type: TagType::from((string) $validated['type']),
            visibility: TagVisibility::from((string) $validated['visibility']),
            moderationStatus: TagModerationStatus::from((string) $validated['moderation_status']),
            source: TagSource::from((string) $validated['source']),
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : null,
        );
        $tag = $this->selectedPublicId === null
            ? $this->tags->create($this->user(), $data)
            : $this->tags->update($this->user(), $this->selectedTag(), $data, $this->tagVersion);
        $this->selectedPublicId = (string) $tag->public_id;
        $this->fillTagForm($this->query->tag((string) $tag->public_id) ?? $tag);
        $this->notice = __('tags.admin.updated');
    }

    public function saveTranslation(string $locale): void
    {
        abort_unless(in_array($locale, config('tags.supported_locales', []), true), 404);
        $form = $this->translationForms[$locale] ?? [];
        $validated = Validator::make(['translation' => $form], [
            'translation.label' => ['required', 'string', 'max:80'],
            'translation.short_description' => ['nullable', 'string', 'max:500'],
            'translation.description' => ['nullable', 'string', 'max:10000'],
            'translation.seo_title' => ['nullable', 'string', 'max:180'],
            'translation.seo_description' => ['nullable', 'string', 'max:320'],
        ])->validate()['translation'];
        $this->tags->saveTranslation($this->user(), $this->selectedTag(), $locale, $validated);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $this->selectedTag());
        $this->notice = __('tags.admin.translation_saved');
    }

    public function addAlias(): void
    {
        $this->tags->addAlias(
            $this->user(),
            $this->selectedTag(),
            $this->aliasName,
            $this->aliasLocale,
            TagAliasSource::Editorial,
        );
        $this->aliasName = '';
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $this->selectedTag());
        $this->notice = __('tags.admin.alias_saved');
    }

    public function removeAlias(int $aliasId): void
    {
        $tag = $this->selectedTag();
        $alias = $tag->aliases()->findOrFail($aliasId);
        $this->tags->removeAlias($this->user(), $tag, $alias);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $tag);
    }

    public function moderateAlias(int $aliasId, string $status): void
    {
        $tag = $this->selectedTag();
        $alias = $tag->aliases()->findOrFail($aliasId);
        $moderationStatus = TagModerationStatus::tryFrom($status);
        abort_if($moderationStatus === null, 404);
        $this->tags->moderateAlias($this->user(), $tag, $alias, $moderationStatus);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $tag);
        $this->notice = __('tags.admin.alias_moderated');
    }

    public function addRelationship(): void
    {
        abort_if($this->relationshipTarget === null, 404);
        $related = $this->query->tag($this->relationshipTarget);
        abort_if($related === null, 404);
        $this->tags->addSynonym($this->user(), $this->selectedTag(), $related, TagSynonymRelationship::Editorial, true);
        $this->relationshipSearch = '';
        $this->relationshipTarget = null;
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $this->selectedTag());
        $this->notice = __('tags.admin.synonym_saved');
    }

    public function removeRelationship(int $synonymId): void
    {
        $tag = $this->selectedTag();
        $synonym = TagSynonym::query()
            ->whereKey($synonymId)
            ->where(fn ($query) => $query
                ->where('tag_id', $tag->id)
                ->orWhere('related_tag_id', $tag->id))
            ->firstOrFail();
        $this->tags->removeSynonym($this->user(), $tag, $synonym);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $tag);
    }

    public function moderateProviderMapping(int $mappingId, string $status): void
    {
        $tag = $this->selectedTag();
        $mapping = $tag->providerMappings()->findOrFail($mappingId);
        $mappingStatus = TagProviderMappingStatus::tryFrom($status);
        abort_if($mappingStatus === null, 404);
        $this->tags->moderateProviderMapping($this->user(), $tag, $mapping, $mappingStatus);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $tag);
        $this->notice = __('tags.admin.provider_mapping_saved');
    }

    public function mergeTag(): void
    {
        abort_if($this->mergeTarget === null, 404);
        $target = $this->query->tag($this->mergeTarget);
        abort_if($target === null, 404);
        $merged = $this->tags->merge($this->user(), $this->selectedTag(), $target);
        $this->selectedPublicId = (string) $merged->public_id;
        $this->mergeTarget = null;
        $this->mergeSearch = '';
        $this->fillTagForm($this->query->tag((string) $merged->public_id) ?? $merged);
        $this->notice = __('tags.admin.merged');
    }

    public function archiveTag(): void
    {
        $tag = $this->tags->archive($this->user(), $this->selectedTag());
        $this->fillTagForm($this->query->tag((string) $tag->public_id) ?? $tag);
        $this->notice = __('tags.admin.archived');
    }

    public function restoreTag(): void
    {
        $tag = $this->tags->restore($this->user(), $this->selectedTag());
        $this->fillTagForm($this->query->tag((string) $tag->public_id) ?? $tag);
        $this->notice = __('tags.admin.updated');
    }

    public function assignTitle(int $titleId): void
    {
        $title = CatalogTitle::query()->findOrFail($titleId);
        $this->assignments->assignGlobal($this->user(), $this->selectedTag(), $title);
        $this->titleSearch = '';
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $this->selectedTag());
    }

    public function removeTitle(int $titleId): void
    {
        $title = CatalogTitle::query()->withTrashed()->findOrFail($titleId);
        $this->assignments->removeGlobal($this->user(), $this->selectedTag(), $title);
        $this->fillTagForm($this->query->tag((string) $this->selectedPublicId) ?? $this->selectedTag());
    }

    public function render(): View
    {
        $tag = $this->selectedPublicId === null ? null : $this->query->tag($this->selectedPublicId);

        return view('livewire.tags.tag-administration-manager', [
            'tagsPage' => $this->query->paginate($this->search, $this->moderationFilter),
            'selectedTag' => $tag,
            'relationshipCandidates' => $this->query->candidates($this->relationshipSearch, $tag),
            'mergeCandidates' => $this->query->candidates($this->mergeSearch, $tag),
            'titleOptions' => $tag === null ? collect() : $this->query->titleOptions($tag, $this->titleSearch),
            'assignedTitles' => $tag === null ? collect() : $this->query->assignedTitles($tag),
            'tagTypes' => $tag === null
                ? array_values(array_filter(TagType::cases(), fn (TagType $type): bool => $type !== TagType::Imported))
                : TagType::cases(),
            'visibilities' => TagVisibility::cases(),
            'moderationStatuses' => TagModerationStatus::cases(),
            'tagSources' => TagSource::cases(),
            'supportedLocales' => config('tags.supported_locales', []),
        ])->extends('layouts.app', [
            'title' => __('tags.admin.title'),
            'seo' => ['title' => __('tags.admin.title'), 'robots' => 'noindex,nofollow', 'alternates' => []],
        ])->section('content');
    }

    private function selectedTag(): Tag
    {
        abort_if($this->selectedPublicId === null, 404);
        $tag = $this->query->tag($this->selectedPublicId);
        abort_if($tag === null, 404);

        return $tag;
    }

    private function fillTagForm(Tag $tag): void
    {
        $this->tagForm = [
            'name' => $tag->canonicalName(),
            'code' => $tag->code ?? '',
            'slug' => $tag->slug,
            'type' => $tag->type->value,
            'visibility' => $tag->visibility->value,
            'moderation_status' => $tag->moderation_status->value,
            'source' => $tag->source->value,
        ];
        $this->tagVersion = $this->tags->version($tag);
        $forms = $this->emptyTranslationForms();

        foreach ($tag->translations as $translation) {
            $forms[$translation->locale] = [
                'label' => (string) $translation->label,
                'short_description' => (string) ($translation->short_description ?? ''),
                'description' => (string) ($translation->description ?? ''),
                'seo_title' => (string) ($translation->seo_title ?? ''),
                'seo_description' => (string) ($translation->seo_description ?? ''),
            ];
        }

        $this->translationForms = $forms;
    }

    /** @return array<string, array<string, string>> */
    private function emptyTranslationForms(): array
    {
        $locales = config('tags.supported_locales', []);

        if (! is_array($locales)) {
            return [];
        }

        return collect($locales)->filter(is_string(...))->mapWithKeys(fn (string $locale): array => [$locale => [
            'label' => '',
            'short_description' => '',
            'description' => '',
            'seo_title' => '',
            'seo_description' => '',
        ]])->all();
    }

    private function resetSecondaryForms(): void
    {
        $this->aliasName = '';
        $this->aliasLocale = 'und';
        $this->relationshipSearch = '';
        $this->relationshipTarget = null;
        $this->mergeSearch = '';
        $this->mergeTarget = null;
        $this->titleSearch = '';
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
