<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Actions\Administration\ApplyAccountRestriction;
use App\Actions\Administration\RevokeAccountRestriction;
use App\DTOs\Administration\AdminTableColumnData;
use App\Enums\AccountRestrictionType;
use App\Enums\AdminPermission;
use App\Exceptions\AdministrationAccessException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\AccountRestriction;
use App\Models\User;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminUserQuery;
use App\Support\Administration\AdminTableState;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class AdminUserDirectoryPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $verification = '';

    #[Url(history: true)]
    public string $restriction = '';

    #[Url(history: true)]
    public string $administrator = '';

    #[Url(history: true)]
    public string $sort = 'registered';

    #[Url(history: true)]
    public string $direction = 'desc';

    #[Url(history: true)]
    public int $perPage = 25;

    /** @var array<string, string> */
    public array $restrictionTypes = [];

    /** @var array<string, string> */
    public array $restrictionReasons = [];

    /** @var array<string, string> */
    public array $restrictionDurations = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(AdminPermission::UsersView->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'verification', 'restriction', 'administrator', 'sort', 'direction', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function applyRestriction(string $userPublicId, ApplyAccountRestriction $action): void
    {
        Gate::authorize(AdminPermission::UsersRestrict->value);
        $type = AccountRestrictionType::tryFrom($this->restrictionTypes[$userPublicId] ?? AccountRestrictionType::UnderReview->value);
        $reason = trim($this->restrictionReasons[$userPublicId] ?? 'manual_review');
        $duration = (int) ($this->restrictionDurations[$userPublicId] ?? 24);

        if ($type === null || ! in_array($duration, [0, 24, 168, 720], true)) {
            throw ValidationException::withMessages(['restriction' => [__('administration.users.invalid_restriction')]]);
        }

        $target = User::query()->where('public_id', $userPublicId)->firstOrFail();
        $actor = $this->user();
        $this->perform(function () use ($action, $actor, $target, $type, $reason, $duration): void {
            $action->handle(
                $actor,
                $target,
                $type,
                $reason,
                $duration === 0 ? null : now()->addHours($duration),
                $type->noticeKey(),
                null,
                true,
            );
            $this->statusMessage = __('administration.users.restriction_applied');
        });
    }

    public function revokeRestriction(string $restrictionPublicId, RevokeAccountRestriction $action): void
    {
        Gate::authorize(AdminPermission::UsersRestrict->value);
        $restriction = AccountRestriction::query()->where('public_id', $restrictionPublicId)->firstOrFail();
        $this->perform(function () use ($action, $restriction): void {
            $action->handle($this->user(), $restriction, 'administrator_restored', true);
            $this->statusMessage = __('administration.users.restriction_revoked');
        });
    }

    public function render(AdminUserQuery $users, AdminAccessResolver $access): View
    {
        Gate::authorize(AdminPermission::UsersView->value);
        $state = AdminTableState::from(
            input: [
                'sort' => $this->sort,
                'direction' => $this->direction,
                'page' => $this->getPage(),
                'per_page' => $this->perPage,
                'search' => $this->search,
                'filters' => [
                    'verification' => $this->verification,
                    'restriction' => $this->restriction,
                    'administrator' => $this->administrator,
                ],
            ],
            sortColumns: ['registered' => 'users.created_at', 'name' => 'users.name'],
            defaultSort: 'registered',
            filterCodes: ['verification', 'restriction', 'administrator'],
        );

        $queryFailed = false;

        try {
            $page = $users->paginate($state);
        } catch (Throwable $exception) {
            report($exception);
            $queryFailed = true;
            $page = new Paginator([], 0, $state->perPage, $state->page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        return view('livewire.administration.users', [
            'users' => $page,
            'queryFailed' => $queryFailed,
            'columns' => $this->columns(),
            'activeFilterCount' => count($state->filters),
            'canRestrict' => $access->allows($this->user(), AdminPermission::UsersRestrict),
            'restrictionTypeOptions' => collect(AccountRestrictionType::cases())->mapWithKeys(
                fn (AccountRestrictionType $type): array => [$type->value => $type->label()],
            )->all(),
        ])->extends('layouts.app', [
            'title' => __('administration.users.title'),
            'seo' => [
                'title' => __('administration.users.title'),
                'description' => __('administration.users.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.users'),
                'alternates' => [],
                'social' => false,
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    /** @return list<AdminTableColumnData> */
    private function columns(): array
    {
        return [
            new AdminTableColumnData('identity', __('administration.users.columns.identity'), 'name', mobilePriority: true),
            new AdminTableColumnData('access', __('administration.users.columns.access')),
            new AdminTableColumnData('activity', __('administration.users.columns.activity')),
            new AdminTableColumnData('registered', __('administration.users.columns.registered'), 'registered'),
            new AdminTableColumnData('actions', __('administration.users.columns.actions')),
        ];
    }

    private function user(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function perform(callable $operation): void
    {
        $this->resetErrorBag('action');

        try {
            $operation();
        } catch (AdministrationAccessException $exception) {
            $this->addError('action', __($exception->translationKey));
        }
    }
}
