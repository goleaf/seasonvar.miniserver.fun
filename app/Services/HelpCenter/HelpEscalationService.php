<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpEscalationData;
use App\Enums\HelpEscalationType;
use App\Models\HelpArticle;
use App\Services\TechnicalIssues\TechnicalIssueContext;
use Illuminate\Routing\Router;

final readonly class HelpEscalationService
{
    public function __construct(private TechnicalIssueContext $issues, private Router $router) {}

    /** @return list<HelpEscalationData> */
    public function for(HelpArticle $article, ?string $routeLocale): array
    {
        return collect([$article->primary_escalation, $article->secondary_escalation])
            ->filter(fn (HelpEscalationType $type): bool => $type !== HelpEscalationType::None)
            ->unique(fn (HelpEscalationType $type): string => $type->value)
            ->map(fn (HelpEscalationType $type): HelpEscalationData => new HelpEscalationData(
                type: $type,
                label: $type->label(),
                description: $type->description(),
                url: $this->url($type, $article, $routeLocale),
                requiresAuthentication: in_array($type, [
                    HelpEscalationType::TechnicalTicket,
                    HelpEscalationType::AccountSupport,
                    HelpEscalationType::PremiumSupport,
                ], true),
            ))
            ->filter(fn (HelpEscalationData $item): bool => $item->url !== null || $item->type === HelpEscalationType::ModerationReport)
            ->values()
            ->all();
    }

    public function technicalSupportUrl(): string
    {
        return $this->issues->featureUrl('general');
    }

    public function contentRequestUrl(?string $routeLocale): ?string
    {
        if (! $this->router->has('requests.create')) {
            return null;
        }

        $name = $routeLocale !== null && $this->router->has('localized.requests.create')
            ? 'localized.requests.create'
            : 'requests.create';

        return route($name, $routeLocale !== null ? ['locale' => $routeLocale] : []);
    }

    private function url(HelpEscalationType $type, HelpArticle $article, ?string $routeLocale): ?string
    {
        if (in_array($type, [
            HelpEscalationType::TechnicalTicket,
            HelpEscalationType::AccountSupport,
            HelpEscalationType::PremiumSupport,
        ], true)) {
            $feature = match ($type) {
                HelpEscalationType::AccountSupport => 'account',
                HelpEscalationType::PremiumSupport => 'premium',
                default => $this->technicalFeature($article),
            };
            $url = $this->issues->helpUrl($feature, $article->public_id);
            $query = [];

            if (is_string($article->escalation_issue_type) && in_array($article->escalation_issue_type, (array) config('help-center.allowed_issue_types', []), true)) {
                $query['type'] = $article->escalation_issue_type;
            }

            return $query === [] ? $url : $url.'&'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($type === HelpEscalationType::ContentRequest && $this->router->has('requests.create')) {
            $url = $this->contentRequestUrl($routeLocale);

            if ($url === null) {
                return null;
            }

            $parameters = [];

            if (is_string($article->escalation_request_type) && in_array($article->escalation_request_type, (array) config('help-center.allowed_request_types', []), true)) {
                $parameters['type'] = $article->escalation_request_type;
            }

            $parameters['help_article'] = $article->public_id;

            return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }

        if ($type === HelpEscalationType::ReturnToFeature && $this->router->has('home')) {
            return $routeLocale !== null && $this->router->has('localized.home')
                ? route('localized.home', ['locale' => $routeLocale])
                : route('home');
        }

        return null;
    }

    private function technicalFeature(HelpArticle $article): string
    {
        return match ($article->feature_code->value) {
            'player', 'audio', 'subtitles', 'quality' => 'player',
            'progress', 'library', 'collections' => 'library',
            'authentication', 'sessions', 'settings', 'privacy', 'security' => 'account',
            'calendar' => 'calendar',
            'notifications' => 'notifications',
            'requests' => 'requests',
            default => 'general',
        };
    }
}
