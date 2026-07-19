<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminRoleCode: string
{
    case Superadministrator = 'superadministrator';
    case PortalAdministrator = 'portal_administrator';
    case ContentManager = 'content_manager';
    case ContentEditor = 'content_editor';
    case Translator = 'translator';
    case MediaManager = 'media_manager';
    case Moderator = 'moderator';
    case SupportAgent = 'support_agent';
    case SeniorSupportAgent = 'senior_support_agent';
    case PremiumManager = 'premium_manager';
    case BillingManager = 'billing_manager';
    case SeoManager = 'seo_manager';
    case SystemOperator = 'system_operator';
    case ReadOnlyAuditor = 'read_only_auditor';

    public function label(): string
    {
        return __("administration.roles.{$this->value}");
    }
}
