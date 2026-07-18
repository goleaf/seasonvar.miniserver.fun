<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\HelpAudience;
use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\User;
use App\Services\Premium\PremiumAccessResolver;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;

final class HelpArticlePolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly PremiumAccessResolver $premium) {}

    public function view(?User $user, HelpArticle $article): bool
    {
        if ($user !== null && Gate::forUser($user)->allows('manage-help-center')) {
            return true;
        }

        if ($article->status !== HelpPublicationStatus::Published || $article->published_at?->isFuture() === true) {
            return false;
        }

        return match ($article->audience) {
            HelpAudience::Everyone => true,
            HelpAudience::Anonymous => $user === null,
            HelpAudience::Authenticated => $user !== null,
            HelpAudience::Premium => $this->premium->resolve($user)->active,
            HelpAudience::Staff => false,
        };
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-help-center');
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-help-center');
    }

    public function update(User $user, HelpArticle $article): bool
    {
        return Gate::forUser($user)->allows('manage-help-center');
    }

    public function publish(User $user, HelpArticle $article): bool
    {
        return Gate::forUser($user)->allows('manage-help-center');
    }

    public function restoreRevision(User $user, HelpArticle $article): bool
    {
        return Gate::forUser($user)->allows('manage-help-center');
    }
}
