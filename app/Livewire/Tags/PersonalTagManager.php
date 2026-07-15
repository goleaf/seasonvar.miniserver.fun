<?php

declare(strict_types=1);

namespace App\Livewire\Tags;

use App\DTOs\PersonalTagData;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Tags\PersonalTagLibraryQuery;
use App\Services\Tags\PersonalTagService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class PersonalTagManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $name = '';

    public string $description = '';

    public ?string $contentLocale = null;

    #[Locked]
    public ?string $editingPublicId = null;

    #[Locked]
    public ?int $editingVersion = null;

    #[Url(as: 'tag', history: true)]
    public ?string $selectedPublicId = null;

    public ?string $status = null;

    protected PersonalTagLibraryQuery $tagQuery;

    protected PersonalTagService $tags;

    protected CatalogTitleQuery $titles;

    public function boot(
        PersonalTagLibraryQuery $tagQuery,
        PersonalTagService $tags,
        CatalogTitleQuery $titles,
    ): void {
        $this->tagQuery = $tagQuery;
        $this->tags = $tags;
        $this->titles = $titles;
    }

    public function mount(): void
    {
        $this->contentLocale = in_array(app()->getLocale(), config('tags.supported_locales', []), true)
            ? app()->getLocale()
            : null;
        $this->tags->purgeExpired($this->user());

        if ($this->selectedPublicId !== null && $this->tagQuery->owned($this->user(), $this->selectedPublicId) === null) {
            $this->selectedPublicId = null;
        }
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 80);
        $this->resetPage(pageName: 'tagPage');
    }

    public function selectTag(string $publicId): void
    {
        abort_if($this->tagQuery->owned($this->user(), $publicId) === null, 404);
        $this->selectedPublicId = $publicId;
        $this->resetPage(pageName: 'tagPage');
    }

    public function startEdit(string $publicId): void
    {
        $tag = $this->tagQuery->owned($this->user(), $publicId);
        abort_if($tag === null, 404);
        $this->editingPublicId = (string) $tag->public_id;
        $this->editingVersion = (int) $tag->content_version;
        $this->name = (string) $tag->name;
        $this->description = (string) ($tag->description ?? '');
        $this->contentLocale = $tag->content_locale;
        $this->status = null;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->status = null;
    }

    public function save(): void
    {
        $user = $this->user();
        $data = new PersonalTagData($this->name, $this->description, $this->contentLocale);

        if ($this->editingPublicId === null) {
            $tag = $this->tags->create($user, $data);
        } else {
            $tag = $this->tagQuery->owned($user, $this->editingPublicId);
            abort_if($tag === null, 404);
            $tag = $this->tags->update($user, $tag, $data, $this->editingVersion);
        }

        $this->selectedPublicId = (string) $tag->public_id;
        $this->resetForm();
        $this->status = __('tags.personal_page.saved');
        $this->resetPage(pageName: 'tagPage');
    }

    public function deleteTag(string $publicId): void
    {
        $tag = $this->tagQuery->owned($this->user(), $publicId, withTrashed: true);
        abort_if($tag === null, 404);
        $this->tags->delete($this->user(), $tag);

        if ($this->selectedPublicId === $publicId) {
            $this->selectedPublicId = null;
        }

        if ($this->editingPublicId === $publicId) {
            $this->resetForm();
        }

        $this->status = __('tags.personal_page.deleted', ['days' => config('tags.restoration_days', 30)]);
        $this->resetPage(pageName: 'tagPage');
    }

    public function restoreTag(string $publicId): void
    {
        $tag = $this->tagQuery->owned($this->user(), $publicId, withTrashed: true);
        abort_if($tag === null, 404);
        $restored = $this->tags->restore($this->user(), $tag);
        $this->selectedPublicId = (string) $restored->public_id;
        $this->status = __('tags.personal_page.restored');
    }

    public function removeAssignment(string $tagPublicId, int $catalogTitleId): void
    {
        $tag = $this->tagQuery->owned($this->user(), $tagPublicId);
        abort_if($tag === null, 404);
        $title = $this->titles->visibleTo($this->user())->whereKey($catalogTitleId)->firstOrFail();
        $this->tags->removeAssignment($this->user(), $tag, $title);
        $this->status = __('tags.personal_page.removed');
        $this->resetPage(pageName: 'tagPage');
    }

    public function render(): View
    {
        $user = $this->user();
        $activeTags = $this->tagQuery->active($user, $this->search);
        $selectedTag = $this->selectedPublicId === null
            ? null
            : $this->tagQuery->owned($user, $this->selectedPublicId);

        return view('livewire.tags.personal-tag-manager', [
            'activeTags' => $activeTags,
            'restorableTags' => $this->tagQuery->restorable($user),
            'selectedTag' => $selectedTag,
            'taggedTitles' => $selectedTag === null ? null : $this->tagQuery->titles($user, $selectedTag),
            'supportedLocales' => config('tags.supported_locales', []),
            'canInteract' => $user->hasVerifiedEmail(),
        ])->extends('layouts.app', [
            'title' => __('tags.personal_page.title'),
            'seo' => [
                'title' => __('tags.personal_page.title'),
                'description' => __('tags.personal_page.lead'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('personal-tags.index'),
                'alternates' => [],
            ],
        ])->section('content');
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->description = '';
        $this->contentLocale = in_array(app()->getLocale(), config('tags.supported_locales', []), true)
            ? app()->getLocale()
            : null;
        $this->editingPublicId = null;
        $this->editingVersion = null;
        $this->resetValidation();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
