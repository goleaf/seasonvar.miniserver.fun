<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueConfirmation;
use App\Models\TechnicalIssueFollower;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueRateLimiter;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SetTechnicalIssueEngagement
{
    public function __construct(private TechnicalIssueSchema $schema, private TechnicalIssueRateLimiter $rateLimiter) {}

    public function confirm(User $user, TechnicalIssue $issue, bool $confirmed): void
    {
        $this->handle($user, $issue, 'confirmation', $confirmed);
    }

    public function follow(User $user, TechnicalIssue $issue, bool $following): void
    {
        $this->handle($user, $issue, 'follow', $following);
    }

    private function handle(User $user, TechnicalIssue $issue, string $kind, bool $enabled): void
    {
        if (! $this->schema->ready()) {
            throw new TechnicalIssueActionException('issues.errors.action_unavailable');
        }

        $ability = $kind === 'confirmation' ? 'confirm' : 'follow';
        Gate::forUser($user)->authorize($enabled ? $ability : 'view', $issue);
        $this->rateLimiter->ensure($user, 'engagement');

        DB::transaction(function () use ($user, $issue, $kind, $enabled, $ability): void {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($user)->authorize($enabled ? $ability : 'view', $locked);
            $model = $kind === 'confirmation' ? TechnicalIssueConfirmation::class : TechnicalIssueFollower::class;

            if ($enabled) {
                $engagement = $model::query()->firstOrCreate(['technical_issue_id' => $locked->id, 'user_id' => $user->id]);
                $changed = $engagement->wasRecentlyCreated;
            } else {
                $changed = $model::query()->where('technical_issue_id', $locked->id)->where('user_id', $user->id)->delete() > 0;
            }

            if ($kind === 'confirmation' && $changed) {
                $locked->version++;
                $locked->save();
            }
        }, attempts: 3);
    }
}
