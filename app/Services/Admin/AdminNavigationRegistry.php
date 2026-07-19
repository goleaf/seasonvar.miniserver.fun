<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminPermission;

final class AdminNavigationRegistry
{
    /**
     * @return list<array{code: string, group: string, route: string, label: string, icon: string, permission: AdminPermission, order: int}>
     */
    public function definitions(): array
    {
        return [
            $this->item('dashboard', 'overview', 'admin.index', 'dashboard', 'fa-solid fa-gauge-high', AdminPermission::DashboardView, 10),
            $this->item('users', 'users', 'admin.users', 'users', 'fa-solid fa-users', AdminPermission::UsersView, 20),
            $this->item('access', 'users', 'admin.access', 'access', 'fa-solid fa-user-shield', AdminPermission::RolesView, 30),
            $this->item('catalog', 'content', 'admin.catalog', 'catalog', 'fa-solid fa-film', AdminPermission::ContentView, 40),
            $this->item('tags', 'content', 'admin.tags', 'tags', 'fa-solid fa-tags', AdminPermission::ContentManage, 50),
            $this->item('calendar', 'content', 'admin.calendar', 'calendar', 'fa-regular fa-calendar-check', AdminPermission::CalendarManage, 60),
            $this->item('comments', 'community', 'admin.comments', 'comments', 'fa-solid fa-comments', AdminPermission::CommentsModerate, 70),
            $this->item('reviews', 'community', 'admin.reviews', 'reviews', 'fa-solid fa-star-half-stroke', AdminPermission::ReviewsModerate, 80),
            $this->item('profiles', 'community', 'admin.profiles', 'profiles', 'fa-solid fa-address-card', AdminPermission::ProfilesModerate, 90),
            $this->item('requests', 'support', 'admin.requests', 'requests', 'fa-solid fa-inbox', AdminPermission::RequestsModerate, 100),
            $this->item('issues', 'support', 'admin.issues', 'issues', 'fa-solid fa-headset', AdminPermission::TicketsSupport, 110),
            $this->item('help', 'support', 'admin.help', 'help', 'fa-solid fa-book-open', AdminPermission::HelpManage, 120),
            $this->item('premium', 'commercial', 'admin.premium', 'premium', 'fa-solid fa-crown', AdminPermission::PremiumView, 130),
            $this->item('imports', 'operations', 'admin.imports', 'imports', 'fa-solid fa-cloud-arrow-down', AdminPermission::ImportsExecute, 140),
            $this->item('audit', 'system', 'admin.audit', 'audit', 'fa-solid fa-clock-rotate-left', AdminPermission::AuditView, 150),
            $this->item('operations', 'system', 'admin.operations', 'operations', 'fa-solid fa-server', AdminPermission::OperationsView, 160),
        ];
    }

    /** @return array{code: string, group: string, route: string, label: string, icon: string, permission: AdminPermission, order: int} */
    private function item(
        string $code,
        string $group,
        string $route,
        string $label,
        string $icon,
        AdminPermission $permission,
        int $order,
    ): array {
        return compact('code', 'group', 'route', 'label', 'icon', 'permission', 'order');
    }
}
