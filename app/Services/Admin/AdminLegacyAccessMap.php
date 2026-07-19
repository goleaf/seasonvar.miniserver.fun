<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;

final readonly class AdminLegacyAccessMap
{
    public function __construct(private AdminAccessRegistry $registry) {}

    /** @return array<string, AdminPermission> */
    public function permissionsForEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $permissions = [];

        if (in_array($email, $this->configuredEmails('administration.bootstrap_superadministrator_emails'), true)) {
            foreach ($this->registry->permissionsFor(AdminRoleCode::Superadministrator) as $permission) {
                $permissions[$permission->value] = $permission;
            }
        }

        if (in_array($email, $this->configuredEmails('seasonvar.admin_emails'), true)) {
            foreach ($this->legacyCatalogPermissions() as $permission) {
                $permissions[$permission->value] = $permission;
            }
        }

        foreach ($this->premiumConfiguration() as $configurationKey => $permission) {
            if (in_array($email, $this->configuredEmails($configurationKey), true)) {
                $permissions[$permission->value] = $permission;
            }
        }

        return $permissions;
    }

    /** @return list<string> */
    public function emailsFor(AdminPermission $permission): array
    {
        $emails = [];

        if (in_array($permission, $this->registry->permissionsFor(AdminRoleCode::Superadministrator), true)) {
            $emails = [...$emails, ...$this->configuredEmails('administration.bootstrap_superadministrator_emails')];
        }

        if (in_array($permission, $this->legacyCatalogPermissions(), true)) {
            $emails = [...$emails, ...$this->configuredEmails('seasonvar.admin_emails')];
        }

        foreach ($this->premiumConfiguration() as $configurationKey => $mappedPermission) {
            if ($mappedPermission === $permission) {
                $emails = [...$emails, ...$this->configuredEmails($configurationKey)];
            }
        }

        return array_values(array_unique($emails));
    }

    /** @return list<AdminPermission> */
    private function legacyCatalogPermissions(): array
    {
        return [
            AdminPermission::AdministrationAccess,
            AdminPermission::DashboardView,
            AdminPermission::ImportsExecute,
            AdminPermission::ContentView,
            AdminPermission::ContentCreate,
            AdminPermission::ContentManage,
            AdminPermission::ContentPublish,
            AdminPermission::ContentDelete,
            AdminPermission::SourcesView,
            AdminPermission::SourcesManage,
            AdminPermission::SourcesDisable,
            AdminPermission::TranslationsManage,
            AdminPermission::SubtitlesManage,
            AdminPermission::CollectionsModerate,
            AdminPermission::CommentsModerate,
            AdminPermission::ReviewsModerate,
            AdminPermission::ProfilesModerate,
            AdminPermission::RequestsModerate,
            AdminPermission::TicketsSupport,
            AdminPermission::CalendarManage,
            AdminPermission::HelpManage,
            AdminPermission::RecommendationsManage,
            AdminPermission::PremiumView,
        ];
    }

    /** @return array<string, AdminPermission> */
    private function premiumConfiguration(): array
    {
        return [
            'premium.administration.grant_emails' => AdminPermission::PremiumGrant,
            'premium.administration.promotion_emails' => AdminPermission::PremiumPromotions,
            'premium.administration.billing_audit_emails' => AdminPermission::BillingView,
            'premium.administration.reconciliation_emails' => AdminPermission::BillingReconcile,
        ];
    }

    /** @return list<string> */
    private function configuredEmails(string $configurationKey): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $configured): string => mb_strtolower(trim((string) $configured)),
            (array) config($configurationKey, []),
        ))));
    }
}
