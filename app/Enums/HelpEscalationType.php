<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpEscalationType: string
{
    case None = 'none';
    case TechnicalTicket = 'technical_ticket';
    case ContentRequest = 'content_request';
    case ModerationReport = 'moderation_report';
    case AccountSupport = 'account_support';
    case PremiumSupport = 'premium_support';
    case RightsHolderContact = 'rights_holder_contact';
    case ReturnToFeature = 'return_to_feature';

    public function label(): string
    {
        return __('help.escalations.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('help.escalations.'.$this->value.'.description');
    }
}
