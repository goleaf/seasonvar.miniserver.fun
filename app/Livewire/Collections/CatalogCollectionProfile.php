<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Illuminate\Contracts\View\View;
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

    public function render(CatalogCollectionQuery $collections, CatalogCollectionSeoPresenter $seo): View
    {
        $owner = User::query()
            ->select(['id', 'public_id', 'name', 'created_at'])
            ->where('public_id', $this->userPublicId)
            ->firstOrFail();
        $localizedAlias = request()->routeIs('localized.profiles.collections');
        $profileCollections = $collections->publicByOwner($owner);

        return view('livewire.collections.catalog-collection-profile', [
            'owner' => $owner,
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
