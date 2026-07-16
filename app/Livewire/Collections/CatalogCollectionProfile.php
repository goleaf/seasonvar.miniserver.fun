<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSchema;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionProfile extends Component
{
    use InteractsWithCollectionLocale;
    use WithPagination;

    #[Locked]
    public string $userPublicId = '';

    public function mount(string $userPublicId, ?string $locale = null): void
    {
        $this->setCollectionLocale($locale);
        abort_unless(Str::isUuid($userPublicId), 404);
        $this->userPublicId = Str::lower($userPublicId);
    }

    public function render(
        CatalogCollectionQuery $collections,
        CatalogCollectionSeoPresenter $seo,
        CatalogCollectionSchema $schema,
    ): View {
        abort_unless($schema->available(), 404);
        $owner = User::query()
            ->select(['id', 'public_id', 'name', 'created_at'])
            ->with(['profile:user_id,username,profile_visibility,collections_visibility,moderation_status'])
            ->where('public_id', $this->userPublicId)
            ->firstOrFail();
        $viewer = auth()->user();
        $canManage = $viewer instanceof User && (
            $viewer->is($owner) || Gate::forUser($viewer)->allows('manage-catalog')
        );
        abort_unless($canManage || ($owner->profile?->sectionIsPublic('collections') ?? false), 404);
        $localizedAlias = request()->routeIs('localized.profiles.collections');
        $profileCollections = $collections->publicByOwner($owner);

        return view('livewire.collections.catalog-collection-profile', [
            'owner' => $owner,
            'publicProfileUrl' => $owner->profile?->isPublic()
                ? route('users.show', ['username' => $owner->profile->username])
                : null,
            'collections' => $profileCollections,
            'localeUrls' => collect($this->collectionLocales())
                ->mapWithKeys(fn (string $locale): array => [$locale => route('localized.profiles.collections', array_filter([
                    'locale' => $locale,
                    'userPublicId' => $owner->public_id,
                    'profileCollectionsPage' => $profileCollections->currentPage() > 1 ? $profileCollections->currentPage() : null,
                ]))])
                ->all(),
        ])->extends('layouts.app', [
            'title' => __('collections.profile.title', ['name' => $owner->name]),
            'seo' => $seo->profile($owner, $localizedAlias, $profileCollections->currentPage() > 1),
        ])->section('content');
    }
}
