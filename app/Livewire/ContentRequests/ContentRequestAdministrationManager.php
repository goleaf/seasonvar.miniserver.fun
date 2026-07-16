<?php

declare(strict_types=1);

namespace App\Livewire\ContentRequests;

use App\Actions\ContentRequests\ChangeContentRequestStatus;
use App\Actions\ContentRequests\ClarifyContentRequest;
use App\Actions\ContentRequests\HandoffContentRequestToImporter;
use App\Actions\ContentRequests\MergeContentRequests;
use App\Actions\ContentRequests\SetContentRequestPriority;
use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestSort;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestQuery;
use App\Services\ContentRequests\ContentRequestSchema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ContentRequestAdministrationManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';
    #[Url(history: true, except: '')]
    public string $type = '';
    #[Url(history: true, except: 'submitted')]
    public string $status = 'submitted';
    #[Url(history: true, except: 'oldest')]
    public string $sort = 'oldest';
    /** @var array<int, string> */
    public array $desiredStatuses = [];
    /** @var array<int, string> */
    public array $priorities = [];
    /** @var array<int, string> */
    public array $rejectionReasons = [];
    /** @var array<int, string> */
    public array $publicReasons = [];
    /** @var array<int, string> */
    public array $privateNotes = [];
    /** @var array<int, string> */
    public array $completionTitleIds = [];
    /** @var array<int, string> */
    public array $completionSeasonIds = [];
    /** @var array<int, string> */
    public array $completionEpisodeIds = [];
    /** @var array<int, string> */
    public array $completionMediaIds = [];
    /** @var array<int, string> */
    public array $mergeTargets = [];
    /** @var array<int, string> */
    public array $clarificationQuestions = [];
    public ?string $statusMessage = null;
    public ?string $actionError = null;

    public function mount(): void
    {
        Gate::authorize('manage-content-requests');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'adminRequestsPage');
        }
    }

    public function changeStatus(int $requestId, ChangeContentRequestStatus $action): void
    {
        $request = ContentRequest::query()->findOrFail($requestId);
        $this->perform(fn (User $user) => $action->handle(
            $user,
            $request->id,
            $this->desiredStatuses[$requestId] ?? $request->status->value,
            $request->version,
            $this->publicReasons[$requestId] ?? null,
            $this->privateNotes[$requestId] ?? null,
            $this->rejectionReasons[$requestId] ?? null,
            [
                'catalog_title_id' => $this->positiveInt($this->completionTitleIds[$requestId] ?? null),
                'season_id' => $this->positiveInt($this->completionSeasonIds[$requestId] ?? null),
                'episode_id' => $this->positiveInt($this->completionEpisodeIds[$requestId] ?? null),
                'media_id' => $this->positiveInt($this->completionMediaIds[$requestId] ?? null),
            ],
        ), __('requests.messages.status_updated'));
    }

    public function setPriority(int $requestId, SetContentRequestPriority $action): void
    {
        $request = ContentRequest::query()->findOrFail($requestId);
        $this->perform(fn (User $user) => $action->handle($user, $request->id, $this->priorities[$requestId] ?? $request->priority->value, $request->version), __('requests.messages.priority_updated'));
    }

    public function merge(int $requestId, MergeContentRequests $action): void
    {
        $publicId = trim($this->mergeTargets[$requestId] ?? '');
        $canonical = ContentRequest::query()->where('public_id', $publicId)->first();

        if ($canonical === null) {
            $this->actionError = __('requests.errors.merge_target_required');

            return;
        }

        $this->perform(fn (User $user) => $action->handle($user, $requestId, $canonical->id), __('requests.messages.merged'));
    }

    public function clarify(int $requestId, ClarifyContentRequest $action): void
    {
        $this->perform(fn (User $user) => $action->ask($user, $requestId, $this->clarificationQuestions[$requestId] ?? '', (string) Str::uuid()), __('requests.messages.clarification_sent'));
    }

    public function handoff(int $requestId, HandoffContentRequestToImporter $action): void
    {
        $this->perform(fn (User $user) => $action->handle($user, $requestId), __('requests.messages.import_handoff'));
    }

    public function render(ContentRequestQuery $query, ContentRequestSchema $schema): View
    {
        Gate::authorize('manage-content-requests');
        $this->normalize();
        $requests = $schema->ready()
            ? $query->administration($this->search, ContentRequestType::tryFrom($this->type), ContentRequestStatus::tryFrom($this->status), ContentRequestSort::tryFrom($this->sort) ?? ContentRequestSort::Oldest)
            : $this->emptyPaginator();
        $this->prepareForms($requests);

        return view('livewire.content-requests.administration-manager', [
            'requests' => $requests,
            'schemaReady' => $schema->ready(),
            'typeOptions' => $this->options(ContentRequestType::cases()),
            'statusOptions' => $this->options(ContentRequestStatus::cases()),
            'priorityOptions' => $this->options(ContentRequestPriority::cases()),
            'sortOptions' => $this->options(ContentRequestSort::cases()),
            'rejectionOptions' => $this->options(ContentRequestRejectionReason::cases()),
        ])->extends('layouts.app', [
            'title' => __('requests.admin.title'),
            'seo' => ['title' => __('requests.admin.title'), 'description' => __('requests.admin.description'), 'robots' => 'noindex, nofollow', 'canonical' => route('admin.requests')],
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 120, '');
        $this->type = ContentRequestType::tryFrom($this->type)?->value ?? '';
        $this->status = ContentRequestStatus::tryFrom($this->status)?->value ?? '';
        $this->sort = ContentRequestSort::tryFrom($this->sort)?->value ?? ContentRequestSort::Oldest->value;
    }

    /** @param LengthAwarePaginator<int, \App\DTOs\ContentRequests\ContentRequestCardData> $requests */
    private function prepareForms(LengthAwarePaginator $requests): void
    {
        $visible = array_fill_keys(collect($requests->items())->map(fn ($request): int => $request->id)->all(), true);

        foreach ([
            'desiredStatuses',
            'priorities',
            'rejectionReasons',
            'publicReasons',
            'privateNotes',
            'completionTitleIds',
            'completionSeasonIds',
            'completionEpisodeIds',
            'completionMediaIds',
            'mergeTargets',
            'clarificationQuestions',
        ] as $property) {
            $this->{$property} = array_intersect_key($this->{$property}, $visible);
        }

        $models = ContentRequest::query()
            ->whereKey(array_keys($visible))
            ->get(['id', 'status', 'priority', 'private_moderator_note'])
            ->keyBy('id');

        foreach ($requests as $request) {
            $model = $models->get($request->id);

            if ($model !== null) {
                $this->desiredStatuses[$request->id] ??= $model->status->value;
                $this->priorities[$request->id] ??= $model->priority->value;
                $this->rejectionReasons[$request->id] ??= ContentRequestRejectionReason::InsufficientInformation->value;
                $this->privateNotes[$request->id] ??= (string) $model->private_moderator_note;
            }
        }
    }

    /** @param array<int, ContentRequestType|ContentRequestStatus|ContentRequestPriority|ContentRequestSort|ContentRequestRejectionReason> $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(static fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : (int) $int;
    }

    private function perform(callable $operation, string $success): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $operation($user);
            $this->statusMessage = $success;
            $this->actionError = null;
        } catch (ContentRequestActionException $exception) {
            $this->statusMessage = null;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (AuthorizationException) {
            $this->statusMessage = null;
            $this->actionError = __('requests.errors.forbidden');
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('requests.errors.action_failed');
        }
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, 25, pageName: 'adminRequestsPage');
    }
}
