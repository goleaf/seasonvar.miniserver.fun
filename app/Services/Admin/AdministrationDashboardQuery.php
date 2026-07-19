<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminDashboardMetricData;
use App\DTOs\Administration\AdminDashboardSectionData;
use App\Enums\AdminPermission;
use App\Enums\ContentRequestStatus;
use App\Enums\TechnicalIssueStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Throwable;

final class AdministrationDashboardQuery
{
    public function __construct(private readonly AdminAccessResolver $access) {}

    /** @return list<AdminDashboardSectionData> */
    public function for(User $user): array
    {
        $permissions = $this->access->permissionsFor($user);
        $sections = [];

        if ($this->hasAny($permissions, AdminPermission::ContentView, AdminPermission::ContentManage)) {
            $sections[] = $this->section(
                code: 'catalog',
                icon: 'fa-solid fa-film',
                requiredTables: ['catalog_titles', 'seasons', 'episodes', 'licensed_media'],
                query: fn (): Builder => DB::query()
                    ->selectSub(DB::table('catalog_titles')->selectRaw('count(*)'), 'titles_total')
                    ->selectSub(DB::table('catalog_titles')->where('is_published', false)->selectRaw('count(*)'), 'titles_unpublished')
                    ->selectSub(DB::table('seasons')->selectRaw('count(*)'), 'seasons_total')
                    ->selectSub(DB::table('episodes')->selectRaw('count(*)'), 'episodes_total')
                    ->selectSub(DB::table('licensed_media')->selectRaw('count(*)'), 'media_total'),
                metricCodes: ['titles_total', 'titles_unpublished', 'seasons_total', 'episodes_total', 'media_total'],
            );
        }

        if ($this->hasAny($permissions, AdminPermission::UsersView)) {
            $sections[] = $this->section(
                code: 'users',
                icon: 'fa-solid fa-users',
                requiredTables: ['users', 'admin_user_roles'],
                query: fn (): Builder => DB::query()
                    ->selectSub(DB::table('users')->selectRaw('count(*)'), 'users_total')
                    ->selectSub(DB::table('users')->whereNull('email_verified_at')->selectRaw('count(*)'), 'users_unverified')
                    ->selectSub(
                        DB::table('admin_user_roles')
                            ->where('status', 'active')
                            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                            ->distinct()
                            ->selectRaw('count(distinct user_id)'),
                        'active_admins',
                    ),
                metricCodes: ['users_total', 'users_unverified', 'active_admins'],
            );
        }

        $moderationMetrics = [];
        $moderationTables = [];
        $moderationQuery = DB::query();

        if ($this->hasAny($permissions, AdminPermission::CommentsModerate)) {
            $moderationTables[] = 'comments';
            $moderationMetrics[] = 'comments_pending';
            $moderationQuery->selectSub(DB::table('comments')->where('status', 'pending')->selectRaw('count(*)'), 'comments_pending');
        }

        if ($this->hasAny($permissions, AdminPermission::ReviewsModerate)) {
            $moderationTables[] = 'catalog_title_reviews';
            $moderationMetrics[] = 'reviews_pending';
            $moderationQuery->selectSub(DB::table('catalog_title_reviews')->where('status', 'pending')->selectRaw('count(*)'), 'reviews_pending');
        }

        if ($this->hasAny($permissions, AdminPermission::ProfilesModerate)) {
            $moderationTables[] = 'user_profile_reports';
            $moderationMetrics[] = 'profiles_reported';
            $moderationQuery->selectSub(DB::table('user_profile_reports')->where('status', 'open')->selectRaw('count(*)'), 'profiles_reported');
        }

        if ($this->hasAny($permissions, AdminPermission::CollectionsModerate)) {
            $moderationTables[] = 'catalog_collection_reports';
            $moderationMetrics[] = 'collections_reported';
            $moderationQuery->selectSub(DB::table('catalog_collection_reports')->where('status', 'open')->selectRaw('count(*)'), 'collections_reported');
        }

        if ($moderationMetrics !== []) {
            $sections[] = $this->section(
                code: 'moderation',
                icon: 'fa-solid fa-shield-halved',
                requiredTables: $moderationTables,
                query: fn (): Builder => $moderationQuery,
                metricCodes: $moderationMetrics,
            );
        }

        $supportMetrics = [];
        $supportTables = [];
        $supportQuery = DB::query();

        if ($this->hasAny($permissions, AdminPermission::RequestsModerate)) {
            $supportTables[] = 'content_requests';
            $supportMetrics[] = 'requests_open';
            $supportQuery->selectSub(
                DB::table('content_requests')->whereIn('status', $this->openContentRequestStatuses())->selectRaw('count(*)'),
                'requests_open',
            );
        }

        if ($this->hasAny($permissions, AdminPermission::TicketsSupport)) {
            $supportTables[] = 'technical_issues';
            $supportMetrics[] = 'tickets_open';
            $supportQuery->selectSub(
                DB::table('technical_issues')->whereIn('status', $this->openTechnicalIssueStatuses())->selectRaw('count(*)'),
                'tickets_open',
            );
        }

        if ($this->hasAny($permissions, AdminPermission::HelpManage)) {
            $supportTables[] = 'help_article_reports';
            $supportMetrics[] = 'help_reports_open';
            $supportQuery->selectSub(DB::table('help_article_reports')->where('status', 'open')->selectRaw('count(*)'), 'help_reports_open');
        }

        if ($supportMetrics !== []) {
            $sections[] = $this->section(
                code: 'support',
                icon: 'fa-solid fa-life-ring',
                requiredTables: $supportTables,
                query: fn (): Builder => $supportQuery,
                metricCodes: $supportMetrics,
            );
        }

        if ($this->hasAny($permissions, AdminPermission::PremiumView)) {
            $billingAllowed = $this->hasAny($permissions, AdminPermission::BillingView);
            $commercialTables = ['premium_entitlements'];
            $commercialMetrics = ['active_entitlements'];
            $commercialQuery = DB::query()->selectSub(
                DB::table('premium_entitlements')
                    ->whereNull('revoked_at')
                    ->where('starts_at', '<=', now())
                    ->where(fn (Builder $query): Builder => $query->where('is_lifetime', true)->orWhereNull('ends_at')->orWhere('ends_at', '>', now()))
                    ->selectRaw('count(*)'),
                'active_entitlements',
            );

            if ($billingAllowed) {
                $commercialTables[] = 'premium_payments';
                $commercialMetrics[] = 'failed_payments';
                $commercialQuery->selectSub(DB::table('premium_payments')->where('status', 'failed')->selectRaw('count(*)'), 'failed_payments');
            }

            $sections[] = $this->section(
                code: 'commercial',
                icon: 'fa-solid fa-crown',
                requiredTables: $commercialTables,
                query: fn (): Builder => $commercialQuery,
                metricCodes: $commercialMetrics,
            );
        }

        if ($this->hasAny($permissions, AdminPermission::OperationsView, AdminPermission::ImportsExecute)) {
            $sections[] = $this->section(
                code: 'operations',
                icon: 'fa-solid fa-server',
                requiredTables: ['failed_jobs', 'seasonvar_import_runs'],
                query: fn (): Builder => DB::query()
                    ->selectSub(DB::table('failed_jobs')->selectRaw('count(*)'), 'failed_jobs')
                    ->selectSub(
                        DB::table('seasonvar_import_runs')
                            ->where(fn (Builder $query): Builder => $query->whereIn('status', ['failed', 'partial'])->orWhere('failed', '>', 0)->orWhere('media_failed', '>', 0))
                            ->selectRaw('count(*)'),
                        'import_failures',
                    ),
                metricCodes: ['failed_jobs', 'import_failures'],
            );
        }

        return $sections;
    }

    /**
     * @param  list<string>  $requiredTables
     * @param  callable(): Builder  $query
     * @param  list<string>  $metricCodes
     */
    private function section(string $code, string $icon, array $requiredTables, callable $query, array $metricCodes): AdminDashboardSectionData
    {
        $readAt = Carbon::now();

        if (collect($requiredTables)->contains(fn (string $table): bool => ! Schema::hasTable($table))) {
            return $this->sectionData($code, $icon, [], false, $readAt);
        }

        try {
            $row = $query()->first();
            $metrics = collect($metricCodes)->map(function (string $metricCode) use ($row): AdminDashboardMetricData {
                $value = (int) ($row->{$metricCode} ?? 0);

                return new AdminDashboardMetricData(
                    code: $metricCode,
                    label: __("administration.dashboard.metrics.{$metricCode}"),
                    value: $value,
                    formattedValue: Number::format($value, locale: app()->getLocale()),
                );
            })->all();

            return $this->sectionData($code, $icon, $metrics, true, $readAt);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sectionData($code, $icon, [], false, $readAt);
        }
    }

    /** @param list<AdminDashboardMetricData> $metrics */
    private function sectionData(string $code, string $icon, array $metrics, bool $available, Carbon $readAt): AdminDashboardSectionData
    {
        return new AdminDashboardSectionData(
            code: $code,
            label: __("administration.dashboard.sections.{$code}"),
            description: __("administration.dashboard.section_descriptions.{$code}"),
            icon: $icon,
            metrics: $metrics,
            available: $available,
            readAtIso: $readAt->toIso8601String(),
            readAtLabel: __('administration.dashboard.freshness', ['time' => $readAt->translatedFormat('d.m.Y H:i')]),
        );
    }

    /** @param array<string, AdminPermission> $permissions */
    private function hasAny(array $permissions, AdminPermission ...$required): bool
    {
        foreach ($required as $permission) {
            if (isset($permissions[$permission->value])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function openContentRequestStatuses(): array
    {
        return array_values(array_map(
            static fn (ContentRequestStatus $status): string => $status->value,
            array_filter(ContentRequestStatus::cases(), static fn (ContentRequestStatus $status): bool => $status->isOpen()),
        ));
    }

    /** @return list<string> */
    private function openTechnicalIssueStatuses(): array
    {
        return array_values(array_map(
            static fn (TechnicalIssueStatus $status): string => $status->value,
            array_filter(TechnicalIssueStatus::cases(), static fn (TechnicalIssueStatus $status): bool => ! $status->isTerminal()),
        ));
    }
}
