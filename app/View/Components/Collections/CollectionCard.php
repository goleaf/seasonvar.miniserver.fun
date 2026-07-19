<?php

declare(strict_types=1);

namespace App\View\Components\Collections;

use App\Models\CatalogCollection;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Collections\CatalogCollectionCoverService;
use App\View\ViewModels\CatalogCollectionCardViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CollectionCard extends Component
{
    public CatalogCollectionCardViewModel $card;

    public bool $compact;

    public function __construct(
        CatalogCollection $collection,
        CatalogCollectionCoverService $covers,
        AccountDateTimeFormatter $dates,
        bool $management = false,
        bool $compact = false,
        ?string $timezone = null,
    ) {
        $this->compact = $compact;
        $this->card = new CatalogCollectionCardViewModel(
            $collection,
            $covers,
            $dates,
            $management,
            $timezone,
        );
    }

    public function render(): View
    {
        return view('components.collections.collection-card');
    }
}
