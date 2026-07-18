<?php

declare(strict_types=1);

namespace App\Livewire\Tags;

use App\DTOs\PersonalTagData;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Tags\PersonalTagLibraryQuery;
use App\Services\Tags\PersonalTagService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PersonalTagSelector extends Component
{
    #[Locked]
    public int $catalogTitleId;

    public bool $isOpen = false;

    /** @var list<string> */
    public array $draftPublicIds = [];

    public string $search = '';

    public string $newName = '';

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

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;

        if (($user = $this->user()) !== null) {
            $this->draftPublicIds = $this->tagQuery->assignedPublicIds($user, $this->title($user));
        }
    }

    public function open(): void
    {
        $user = $this->authorizedUser();
        $this->draftPublicIds = $this->tagQuery->assignedPublicIds($user, $this->title($user));
        $this->isOpen = true;
        $this->status = null;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $user = $this->authorizedUser();
        $this->draftPublicIds = $this->tagQuery->assignedPublicIds($user, $this->title($user));
        $this->isOpen = false;
        $this->search = '';
        $this->newName = '';
        $this->status = null;
        $this->resetValidation();
    }

    public function toggle(string $publicId): void
    {
        $user = $this->authorizedUser();
        abort_if($this->tagQuery->owned($user, $publicId) === null, 404);

        $this->draftPublicIds = in_array($publicId, $this->draftPublicIds, true)
            ? array_values(array_diff($this->draftPublicIds, [$publicId]))
            : array_values(array_unique([...$this->draftPublicIds, $publicId]));
    }

    public function createAndSelect(): void
    {
        $user = $this->authorizedUser();
        $this->resetErrorBag(['newName', 'name']);

        try {
            $tag = $this->tags->create($user, new PersonalTagData(
                name: $this->newName,
            ));
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['name'] ?? [__('tags.states.error')] as $message) {
                $this->addError('newName', $message);
            }

            return;
        }

        $this->draftPublicIds = array_values(array_unique([...$this->draftPublicIds, (string) $tag->public_id]));
        $this->newName = '';
        $this->search = '';
        $this->status = __('tags.personal_page.saved');
    }

    public function apply(): void
    {
        $user = $this->authorizedUser();
        $this->tags->reconcileAssignments($user, $this->title($user), $this->draftPublicIds);
        $this->draftPublicIds = $this->tagQuery->assignedPublicIds($user, $this->title($user));
        $this->isOpen = false;
        $this->search = '';
        $this->newName = '';
        $this->status = __('tags.personal_page.assigned');
    }

    public function render(): View
    {
        $user = $this->user();
        $allTags = $user === null ? collect() : $this->tagQuery->active($user, $this->search);
        $assignedIds = $user === null
            ? []
            : $this->tagQuery->assignedPublicIds($user, $this->title($user));
        $personalTagsById = $user === null
            ? collect()
            : $this->tagQuery->active($user)->keyBy('public_id');
        $assignedTags = collect($assignedIds)
            ->map(fn (string $publicId) => $personalTagsById->get($publicId))
            ->filter()
            ->values();

        return view('livewire.tags.personal-tag-selector', [
            'isAuthenticated' => $user !== null,
            'canInteract' => $user?->hasVerifiedEmail() === true,
            'allTags' => $allTags,
            'assignedTags' => $assignedTags,
        ]);
    }

    private function title(User $user): CatalogTitle
    {
        return $this->titles->visibleTo($user)->whereKey($this->catalogTitleId)->firstOrFail();
    }

    private function authorizedUser(): User
    {
        $user = $this->user();
        abort_unless($user?->hasVerifiedEmail() === true, 403);

        return $user;
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
