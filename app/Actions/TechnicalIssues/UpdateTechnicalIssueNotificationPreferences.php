<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\Models\TechnicalIssueNotificationPreference;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final readonly class UpdateTechnicalIssueNotificationPreferences
{
    public function __construct(private TechnicalIssueSchema $schema) {}

    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data): TechnicalIssueNotificationPreference
    {
        Gate::forUser($user)->authorize('update-account-settings');
        abort_unless($this->schema->ready(), 503);
        $validated = Validator::make($data, [
            'requester_updates' => ['required', 'boolean'],
            'confirmer_updates' => ['required', 'boolean'],
            'follower_updates' => ['required', 'boolean'],
            'support_replies' => ['required', 'boolean'],
        ])->validate();

        return TechnicalIssueNotificationPreference::query()->updateOrCreate(['user_id' => $user->id], $validated);
    }
}
