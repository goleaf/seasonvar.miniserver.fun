<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpArticleType: string
{
    case Faq = 'faq';
    case Troubleshooting = 'troubleshooting';
    case HowTo = 'how_to';
    case PolicyExplanation = 'policy_explanation';
    case FeatureGuide = 'feature_guide';
    case AccountHelp = 'account_help';
    case PlayerHelp = 'player_help';
    case PremiumHelp = 'premium_help';
    case AccessibilityHelp = 'accessibility_help';
    case KnownLimitation = 'known_limitation';
    case SupportEntry = 'support_entry';

    public function label(): string
    {
        return __('help.article_types.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('help.article_types.'.$this->value.'.description');
    }

    public function usesFaqPresentation(): bool
    {
        return $this === self::Faq;
    }

    public function searchPriority(): int
    {
        return match ($this) {
            self::Troubleshooting, self::AccountHelp, self::PlayerHelp, self::AccessibilityHelp => 4,
            self::HowTo, self::SupportEntry => 3,
            self::Faq, self::PremiumHelp, self::KnownLimitation => 2,
            self::FeatureGuide, self::PolicyExplanation => 1,
        };
    }

    public function categoryEligible(string $categoryCode): bool
    {
        $eligible = match ($this) {
            self::AccountHelp => ['account_security', 'profile_settings'],
            self::PlayerHelp => ['watching_video', 'audio_subtitles', 'devices_accessibility'],
            self::PremiumHelp => ['premium_availability'],
            self::AccessibilityHelp => ['devices_accessibility'],
            self::SupportEntry => ['support_requests', 'getting_started'],
            default => [],
        };

        return $eligible === [] || in_array($categoryCode, $eligible, true);
    }

    public function supportsEscalation(): bool
    {
        return true;
    }

    public function feedbackEnabled(): bool
    {
        return $this !== self::SupportEntry;
    }

    public function faqSchemaEligible(): bool
    {
        return $this->usesFaqPresentation();
    }
}
