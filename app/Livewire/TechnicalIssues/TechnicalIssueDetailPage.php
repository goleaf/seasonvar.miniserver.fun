<?php

declare(strict_types=1);

namespace App\Livewire\TechnicalIssues;

use App\Actions\TechnicalIssues\AddTechnicalIssueMessage;
use App\Actions\TechnicalIssues\SetTechnicalIssueEngagement;
use App\Actions\TechnicalIssues\TechnicalIssueWorkflow;
use App\Enums\AdminPermission;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Services\Admin\AdminEligibleUserQuery;
use App\Services\TechnicalIssues\TechnicalIssueQuery;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use App\Services\TechnicalIssues\TechnicalIssueSourceHealthService;
use App\Services\TechnicalIssues\TechnicalIssueTextSanitizer;
use App\Services\TechnicalIssues\TechnicalIssueTypeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

final class TechnicalIssueDetailPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithFileUploads;

    #[Locked]
    public string $issuePublicId;

    #[Locked]
    public string $messageToken = '';

    #[Locked]
    public string $issueLocale = 'ru';

    public string $summary = '';

    public string $expectedBehavior = '';

    public string $actualBehavior = '';

    public string $reproductionSteps = '';

    public string $messageBody = '';

    public string $internalNote = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $messageScreenshots = [];

    public string $desiredStatus = '';

    public string $publicReason = '';

    public string $privateNote = '';

    public string $rejectionReason = 'insufficient_information';

    public string $reroutedTo = '';

    public string $severity = 'medium';

    public string $priority = 'normal';

    public string $supportTeam = 'support';

    public string $assigneeId = '';

    public string $resolutionType = '';

    public string $resolutionSummary = '';

    public string $resolutionPrivateNote = '';

    public string $reopenReason = '';

    public string $mergeNumber = '';

    public string $redactionField = 'summary';

    public string $sourceAction = 'under_review';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(
        string $technicalIssue,
        TechnicalIssueSchema $schema,
        TechnicalIssueTextSanitizer $text,
    ): void {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->issueLocale = App::getLocale();
        $this->issuePublicId = Str::lower($technicalIssue);
        $this->messageToken = (string) Str::uuid();

        if (! $schema->ready()) {
            return;
        }

        $issue = TechnicalIssue::query()->where('public_id', $this->issuePublicId)->firstOrFail();
        Gate::forUser($user)->authorize('view', $issue);
        $staff = Gate::forUser($user)->allows('manage-technical-issues');

        if ($staff || $issue->requester_id === $user->id) {
            $this->summary = (string) $text->display($issue->summary);
            $this->expectedBehavior = (string) $text->display($issue->expected_behavior);
            $this->actualBehavior = (string) $text->display($issue->actual_behavior);
            $this->reproductionSteps = (string) $text->display($issue->reproduction_steps);
        }

        if ($staff) {
            $this->syncStaffState($issue);
        }
    }

    public function hydrate(): void
    {
        if (in_array($this->issueLocale, config('technical-issues.supported_locales', []), true)) {
            App::setLocale($this->issueLocale);
        }
    }

    public function setConfirm(bool $desired, SetTechnicalIssueEngagement $action): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $action->confirm($user, $issue, $desired), __('issues.states.engagement_saved'));
    }

    public function setFollow(bool $desired, SetTechnicalIssueEngagement $action): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $action->follow($user, $issue, $desired), __('issues.states.engagement_saved'));
    }

    public function save(TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->updateRequester($user, $issue, [
            'summary' => $this->summary,
            'expected_behavior' => $this->expectedBehavior,
            'actual_behavior' => $this->actualBehavior,
            'reproduction_steps' => $this->reproductionSteps,
        ]), __('issues.states.saved'));
    }

    public function withdraw(TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->withdraw($user, $issue), __('issues.states.withdrawn'));

        if ($this->actionError === null) {
            $this->redirect($this->mineUrl(), navigate: true);
        }
    }

    public function sendMessage(AddTechnicalIssueMessage $action): void
    {
        $this->addMessage($action, false);
    }

    public function addInternalNote(AddTechnicalIssueMessage $action): void
    {
        $this->addMessage($action, true);
    }

    public function applyStatus(TechnicalIssueWorkflow $workflow): void
    {
        $next = TechnicalIssueStatus::tryFrom($this->desiredStatus);

        if ($next === null) {
            $this->actionError = __('issues.errors.invalid_transition');

            return;
        }

        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->transition(
            $user,
            $issue,
            $next,
            'support_status_changed',
            $this->nullable($this->publicReason),
            $this->nullable($this->privateNote),
            $next === TechnicalIssueStatus::Rejected ? $this->rejectionReason : null,
            $this->nullable($this->reroutedTo),
        ), __('issues.states.admin_saved'));
    }

    public function classify(TechnicalIssueWorkflow $workflow): void
    {
        $severity = TechnicalIssueSeverity::tryFrom($this->severity);
        $priority = TechnicalIssuePriority::tryFrom($this->priority);

        if ($severity === null || $priority === null) {
            $this->actionError = __('issues.errors.invalid_classification');

            return;
        }

        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->classify($user, $issue, $severity, $priority), __('issues.states.admin_saved'));
    }

    public function assign(TechnicalIssueWorkflow $workflow): void
    {
        $assignee = filter_var($this->assigneeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->assign(
            $user,
            $issue,
            is_int($assignee) ? $assignee : null,
            $this->supportTeam,
        ), __('issues.states.admin_saved'));
    }

    public function resolve(TechnicalIssueWorkflow $workflow): void
    {
        $resolution = TechnicalIssueResolutionType::tryFrom($this->resolutionType);

        if ($resolution === null) {
            $this->actionError = __('issues.errors.invalid_resolution');

            return;
        }

        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->resolve(
            $user,
            $issue,
            $resolution,
            $this->resolutionSummary,
            $this->nullable($this->resolutionPrivateNote),
        ), __('issues.states.admin_saved'));
    }

    public function verify(bool $fixed, TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->verify($user, $issue, $fixed, $this->nullable($this->reopenReason)), __('issues.states.admin_saved'));
    }

    public function reopen(TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->reopen($user, $issue, $this->reopenReason), __('issues.states.admin_saved'));
    }

    public function merge(TechnicalIssueWorkflow $workflow): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('manage-technical-issues');
        $canonical = TechnicalIssue::query()->where('public_number', Str::upper(trim($this->mergeNumber)))->first();

        if (! $canonical instanceof TechnicalIssue) {
            $this->actionError = __('issues.errors.merge_target_required');

            return;
        }

        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->merge($user, $issue, $canonical), __('issues.states.admin_saved'));
    }

    public function redact(TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->redact($user, $issue, $this->redactionField), __('issues.states.admin_saved'));
    }

    public function redactMessage(string $messagePublicId, TechnicalIssueWorkflow $workflow): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $workflow->redactMessage($user, $issue, $messagePublicId), __('issues.states.admin_saved'));
    }

    public function applySourceAction(TechnicalIssueSourceHealthService $sourceHealth): void
    {
        $this->perform(fn (User $user, TechnicalIssue $issue) => $sourceHealth->apply($user, $issue, $this->sourceAction, $this->nullable($this->privateNote)), __('issues.states.admin_saved'));
    }

    public function removeMessageScreenshot(int $index): void
    {
        if (array_key_exists($index, $this->messageScreenshots)) {
            unset($this->messageScreenshots[$index]);
            $this->messageScreenshots = array_values($this->messageScreenshots);
        }
    }

    public function render(
        TechnicalIssueQuery $query,
        TechnicalIssueTypeRegistry $types,
        TechnicalIssueSchema $schema,
        AdminEligibleUserQuery $eligibleAdministrators,
    ): View {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (! $schema->ready()) {
            return view('livewire.technical-issues.unavailable', [
                'message' => __('issues.errors.action_unavailable'),
                'returnUrl' => $this->mineUrl(),
            ])->extends('layouts.app', [
                'title' => __('issues.title'),
                'seo' => [
                    'title' => __('issues.title'),
                    'description' => __('issues.detail.private_description'),
                    'robots' => 'noindex, nofollow, noarchive',
                    'canonical' => request()->url(),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])->section('content');
        }

        $issue = TechnicalIssue::query()->where('public_id', $this->issuePublicId)->firstOrFail();
        Gate::forUser($user)->authorize('view', $issue);

        try {
            $detail = $query->detail($user, $issue);
        } catch (Throwable $exception) {
            report($exception);

            return view('livewire.technical-issues.unavailable', [
                'message' => __('issues.errors.query_failed'),
                'returnUrl' => $this->mineUrl(),
            ])->extends('layouts.app', [
                'title' => $issue->public_number,
                'seo' => [
                    'title' => $issue->public_number,
                    'description' => __('issues.detail.private_description'),
                    'robots' => 'noindex, nofollow, noarchive',
                    'canonical' => request()->url(),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])->section('content');
        }

        $staff = $detail->viewerMode === 'staff';
        $statusOptions = $staff
            ? collect(TechnicalIssueStatus::cases())
                ->reject(fn (TechnicalIssueStatus $status): bool => $status->requiresDedicatedAction())
                ->filter(fn (TechnicalIssueStatus $status): bool => $status !== $issue->status && $issue->status->canTransitionTo($status))
                ->map(fn (TechnicalIssueStatus $status): array => ['value' => $status->value, 'label' => $status->label()])
                ->values()
                ->all()
            : [];

        if ($staff && ! in_array($this->desiredStatus, array_column($statusOptions, 'value'), true)) {
            $this->desiredStatus = $statusOptions[0]['value'] ?? '';
        }

        $assignees = $staff
            ? $eligibleAdministrators->forPermission(AdminPermission::TicketsSupport)->orderBy('name')->get(['id', 'name'])->map(fn (User $agent): array => ['value' => $agent->id, 'label' => $agent->name])->all()
            : [];

        return view('livewire.technical-issues.detail-page', [
            'issue' => $detail,
            'statusOptions' => $statusOptions,
            'severityOptions' => $this->options(TechnicalIssueSeverity::cases()),
            'priorityOptions' => $this->options(TechnicalIssuePriority::cases()),
            'resolutionOptions' => $this->options($types->resolutions($issue->type)),
            'teamOptions' => collect((array) config('technical-issues.support_teams', []))->map(fn (string $team): array => ['value' => $team, 'label' => __('issues.support_teams.'.$team)])->all(),
            'assigneeOptions' => $assignees,
            'rejectionOptions' => collect($this->rejectionReasons())->map(fn (string $reason): array => ['value' => $reason, 'label' => __('issues.rejection_reasons.'.$reason)])->all(),
            'rerouteOptions' => collect(['', 'content_request', 'moderation_report', 'account_security', 'rights_holder'])->map(fn (string $route): array => ['value' => $route, 'label' => __('issues.reroutes.'.($route === '' ? 'none' : $route))])->all(),
            'sourceActionOptions' => collect((array) config('technical-issues.source_actions', []))->map(fn (string $action): array => ['value' => $action, 'label' => __('issues.source_actions.'.$action)])->all(),
            'canResolve' => $staff && $issue->status !== TechnicalIssueStatus::Resolved && $issue->status->canTransitionTo(TechnicalIssueStatus::Resolved),
            'canAssign' => $staff && ! $issue->status->isTerminal(),
        ])->extends('layouts.app', [
            'title' => $detail->card->number,
            'seo' => [
                'title' => $detail->card->number,
                'description' => __('issues.detail.private_description'),
                'robots' => 'noindex, nofollow, noarchive',
                'canonical' => $detail->card->url,
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function addMessage(AddTechnicalIssueMessage $action, bool $internal): void
    {
        $body = $internal ? $this->internalNote : $this->messageBody;
        $files = $this->uploadedFiles($this->messageScreenshots);
        $this->perform(fn (User $user, TechnicalIssue $issue) => $action->handle($user, $issue, $body, $this->messageToken, $internal, $files), __('issues.states.message_sent'));

        if ($this->actionError === null) {
            $this->messageBody = '';
            $this->internalNote = '';
            $this->messageScreenshots = [];
            $this->messageToken = (string) Str::uuid();
        }
    }

    private function perform(callable $operation, string $success): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $issue = TechnicalIssue::query()->where('public_id', $this->issuePublicId)->firstOrFail();

        try {
            $operation($user, $issue);
            $this->statusMessage = $success;
            $this->actionError = null;
            $this->syncState();
        } catch (TechnicalIssueActionException $exception) {
            $this->statusMessage = null;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (AuthorizationException) {
            $this->statusMessage = null;
            $this->actionError = __('issues.errors.forbidden');
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('issues.errors.action_failed');
        }
    }

    private function syncState(): void
    {
        $issue = TechnicalIssue::query()->where('public_id', $this->issuePublicId)->firstOrFail();
        $user = auth()->user();

        if (! $user instanceof User || ! Gate::forUser($user)->allows('manage-technical-issues')) {
            return;
        }

        $this->syncStaffState($issue);
    }

    private function syncStaffState(TechnicalIssue $issue): void
    {
        $this->desiredStatus = $issue->status->value;
        $this->severity = $issue->severity->value;
        $this->priority = $issue->priority->value;
        $this->supportTeam = $issue->support_team;
        $this->assigneeId = $issue->assigned_to_id !== null ? (string) $issue->assigned_to_id : '';
    }

    /**
     * @param  array<int, TechnicalIssueSeverity|TechnicalIssuePriority|TechnicalIssueResolutionType>  $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(static fn ($value): array => ['value' => $value->value, 'label' => $value->label()], $values);
    }

    /**
     * @param  array<int, mixed>  $files
     * @return list<UploadedFile>
     */
    private function uploadedFiles(array $files): array
    {
        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /** @return list<string> */
    private function rejectionReasons(): array
    {
        return ['invalid', 'insufficient_information', 'abusive', 'duplicate', 'unrelated', 'feature_request', 'content_request', 'intended_behavior', 'unsupported_environment'];
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function mineUrl(): string
    {
        return in_array(App::getLocale(), config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.mine', ['locale' => App::getLocale()])
            : route('issues.mine');
    }
}
