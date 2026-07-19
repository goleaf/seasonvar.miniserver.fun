<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Enums\HelpFeature;
use App\Services\HelpCenter\HelpContextualLinkService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Lazy(isolate: false)]
final class ContextualHelpLink extends Component
{
    #[Locked]
    public string $feature;

    #[Locked]
    public string $context;

    #[Locked]
    public ?string $routeLocale = null;

    public function mount(string $feature, string $context, ?string $routeLocale = null): void
    {
        abort_unless(HelpFeature::tryFrom($feature) instanceof HelpFeature, 404);
        abort_unless(preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $context) === 1, 404);

        $this->feature = $feature;
        $this->context = $context;
        $this->routeLocale = $routeLocale;
    }

    public function placeholder(): View
    {
        return view('livewire.help-center.contextual-link-placeholder');
    }

    public function render(HelpContextualLinkService $links): View
    {
        return view('livewire.help-center.contextual-link', [
            'helpLink' => $links->primary(
                HelpFeature::from($this->feature),
                $this->context,
                app()->getLocale(),
                $this->routeLocale,
            ),
        ]);
    }
}
