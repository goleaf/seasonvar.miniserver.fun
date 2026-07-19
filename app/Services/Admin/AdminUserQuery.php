<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminUserData;
use App\Enums\AdminMembershipStatus;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Support\Administration\AdminTableState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AdminUserQuery
{
    /** @return LengthAwarePaginator<int, AdminUserData> */
    public function paginate(AdminTableState $state): LengthAwarePaginator
    {
        $query = User::query()
            ->select(['id', 'public_id', 'name', 'email', 'email_verified_at', 'created_at'])
            ->withCount(['comments', 'catalogTitleReviews', 'contentRequests'])
            ->with([
                'adminRoleMemberships' => fn ($query) => $query
                    ->select(['id', 'user_id', 'admin_role_id', 'status', 'expires_at'])
                    ->where('status', AdminMembershipStatus::Active->value)
                    ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->with('role:id,code,is_active'),
                'accountRestrictions' => fn ($query) => $query
                    ->select(['id', 'public_id', 'user_id', 'type', 'starts_at', 'expires_at', 'revoked_at'])
                    ->active()
                    ->latest('starts_at'),
            ]);

        $this->applySearch($query, $state->search);
        $this->applyFilters($query, $state->filters);
        $query->orderBy($state->sortColumn(), $state->direction)->orderBy('users.id', $state->direction);

        $paginator = $query->paginate($state->perPage, page: $state->page);

        return $paginator->through(fn (User $user): AdminUserData => new AdminUserData(
            publicId: (string) $user->public_id,
            name: (string) $user->name,
            maskedEmail: $this->maskEmail((string) $user->email),
            verificationLabel: $user->email_verified_at === null
                ? __('administration.users.unverified')
                : __('administration.users.verified'),
            roleLabels: $user->adminRoleMemberships
                ->filter(fn (AdminUserRole $membership): bool => $membership->role->is_active)
                ->map(fn (AdminUserRole $membership): string => $membership->role->code->label())
                ->unique()
                ->values()
                ->all(),
            restrictionLabels: $user->accountRestrictions
                ->map(fn ($restriction): string => $restriction->type->label())
                ->unique()
                ->values()
                ->all(),
            restrictionPublicIds: $user->accountRestrictions->pluck('public_id')->map(strval(...))->values()->all(),
            commentsCount: (int) $user->comments_count,
            reviewsCount: (int) $user->catalog_title_reviews_count,
            requestsCount: (int) $user->content_requests_count,
            registeredAtLabel: $user->created_at?->translatedFormat('d.m.Y H:i') ?? '—',
        ));
    }

    /** @param Builder<User> $query */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        if (Str::isUuid($search)) {
            $query->where('public_id', $search);

            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            $query->where('name', 'like', '%'.$search.'%')
                ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower($search).'%']);
        });
    }

    /** @param Builder<User> $query @param array<string, string> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['verification'] ?? null) === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif (($filters['verification'] ?? null) === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        if (($filters['restriction'] ?? null) === 'active') {
            $query->whereHas('accountRestrictions', fn (Builder $query): Builder => $query->active());
        } elseif (($filters['restriction'] ?? null) === 'none') {
            $query->whereDoesntHave('accountRestrictions', fn (Builder $query): Builder => $query->active());
        }

        if (($filters['administrator'] ?? null) === 'active') {
            $query->whereHas('adminRoleMemberships', fn (Builder $query): Builder => $query
                ->where('status', AdminMembershipStatus::Active->value)
                ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())));
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
