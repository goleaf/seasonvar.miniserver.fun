<?php

declare(strict_types=1);

namespace App\View\Components\Collections;

use App\Models\CatalogCollection;
use App\Services\Collections\CatalogCollectionCoverService;
use App\View\ViewModels\CatalogCollectionCardViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CollectionCard extends Component
{
    public CatalogCollectionCardViewModel $card;

    public function __construct(
        CatalogCollection $collection,
        CatalogCollectionCoverService $covers,
        bool $management = false,
    ) {
        $this->card = new CatalogCollectionCardViewModel(
            $collection,
            $covers,
            $management,
        );
    }

    public function render(): View
    {
        return view('components.collections.collection-card');
    }
}
