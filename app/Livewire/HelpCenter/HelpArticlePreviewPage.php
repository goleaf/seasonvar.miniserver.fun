<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use App\Models\User;
use App\Services\HelpCenter\HelpArticleRenderer;
use App\Services\HelpCenter\HelpLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class HelpArticlePreviewPage extends Component
{
    #[Locked]
    public string $articlePublicId = '';

    #[Locked]
    public string $locale = 'ru';

    public function mount(HelpArticle $helpArticle, ?string $locale, HelpLocale $locales): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('update', $helpArticle);
        $this->articlePublicId = $helpArticle->public_id;
        $this->locale = $locales->normalize($locale);
    }

    public function render(HelpArticleRenderer $renderer): View
    {
        $article = HelpArticle::query()->where('public_id', $this->articlePublicId)->with(['translations', 'category.translations'])->firstOrFail();
        Gate::authorize('update', $article);
        $translation = $article->translations->firstWhere('locale', $this->locale);
        abort_unless($translation instanceof HelpArticleTranslation, 404);

        return view('livewire.help-center.preview', [
            'article' => $article,
            'translation' => $translation,
            'content' => $renderer->render($translation->body_markdown, $translation->locale),
        ])->extends('layouts.app', [
            'title' => __('help.admin.preview'),
            'seo' => ['robots' => 'noindex, nofollow', 'social' => false, 'alternates' => []],
        ])->section('content');
    }
}
