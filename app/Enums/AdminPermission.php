<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminPermission: string
{
    case AdministrationAccess = 'administration.access';
    case DashboardView = 'administration.dashboard.view';
    case RolesView = 'administration.roles.view';
    case RolesManage = 'administration.roles.manage';
    case AdministratorsView = 'administration.administrators.view';
    case AdministratorsManage = 'administration.administrators.manage';
    case UsersView = 'users.view';
    case UsersRestrict = 'users.restrict';
    case UsersExport = 'users.export';
    case UsersDelete = 'users.delete';
    case UsersMerge = 'users.merge';
    case ContentView = 'content.view';
    case ContentCreate = 'content.create';
    case ContentManage = 'content.manage';
    case ContentPublish = 'content.publish';
    case ContentDelete = 'content.delete';
    case SourcesView = 'sources.view';
    case SourcesManage = 'sources.manage';
    case SourcesDisable = 'sources.disable';
    case TranslationsManage = 'translations.manage';
    case SubtitlesManage = 'subtitles.manage';
    case CollectionsModerate = 'moderation.collections';
    case CommentsModerate = 'moderation.comments';
    case ReviewsModerate = 'moderation.reviews';
    case ProfilesModerate = 'moderation.profiles';
    case RequestsModerate = 'moderation.requests';
    case TicketsSupport = 'support.tickets';
    case TicketInternalNotes = 'support.tickets.internal_notes';
    case TicketAttachments = 'support.tickets.attachments';
    case HelpManage = 'help.manage';
    case CalendarManage = 'calendar.manage';
    case RecommendationsManage = 'recommendations.manage';
    case PremiumView = 'premium.view';
    case PremiumGrant = 'premium.grant';
    case PremiumPromotions = 'premium.promotions.manage';
    case BillingView = 'billing.view';
    case BillingRefund = 'billing.refund';
    case BillingReconcile = 'billing.reconcile';
    case AdvertisersView = 'advertisers.view';
    case AdvertisersManage = 'advertisers.manage';
    case AdvertiserBilling = 'advertisers.billing';
    case RightsCasesView = 'rights.cases.view';
    case RightsIdentityDocuments = 'rights.identity_documents.view';
    case RightsAuthorityDocuments = 'rights.authority_documents.view';
    case RightsDecide = 'rights.decide';
    case RightsExport = 'rights.export';
    case NotificationsManage = 'notifications.manage';
    case ImportsExecute = 'imports.execute';
    case CacheView = 'cache.view';
    case CacheInvalidate = 'cache.invalidate';
    case SearchView = 'search.index.view';
    case SearchReindex = 'search.index.rebuild';
    case SeoManage = 'seo.manage';
    case RedirectsManage = 'redirects.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';
    case OperationsView = 'operations.view';
    case OperationsExecute = 'operations.execute';

    public function sensitivity(): AdminPermissionSensitivity
    {
        return match ($this) {
            self::RolesManage,
            self::AdministratorsManage,
            self::UsersDelete,
            self::UsersMerge,
            self::BillingRefund,
            self::BillingReconcile,
            self::AdvertiserBilling,
            self::RightsIdentityDocuments,
            self::RightsAuthorityDocuments,
            self::RightsDecide,
            self::RightsExport,
            self::SettingsManage,
            self::AuditExport,
            self::OperationsExecute => AdminPermissionSensitivity::HighlySensitive,

            self::UsersRestrict,
            self::UsersExport,
            self::ContentDelete,
            self::SourcesDisable,
            self::TicketInternalNotes,
            self::TicketAttachments,
            self::PremiumGrant,
            self::BillingView,
            self::AdvertisersManage,
            self::ImportsExecute,
            self::CacheInvalidate,
            self::SearchReindex,
            self::RedirectsManage => AdminPermissionSensitivity::Sensitive,

            default => AdminPermissionSensitivity::Standard,
        };
    }

    public function label(): string
    {
        return __('administration.permissions.'.str_replace('.', '_', $this->value));
    }
}
