<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminCapabilityData;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Schema;

final readonly class AdminCapabilityRegistry
{
    public function __construct(private PremiumPaymentGatewayRegistry $payments) {}

    /** @return list<AdminCapabilityData> */
    public function all(): array
    {
        return [
            $this->capability('database_search', class_exists(CatalogSearchIndexer::class)
                && Schema::hasTable('catalog_title_search_documents')
                && Schema::hasTable('catalog_search_index_states')),
            $this->capability('cache_versions', class_exists(CacheVersionRegistry::class)),
            $this->capability('scheduler', is_file(base_path('routes/console.php'))),
            $this->capability('queues', Schema::hasTable('jobs') && Schema::hasTable('failed_jobs') && config('queue.default') !== 'sync'),
            $this->capability('payment_provider', $this->payments->codes() !== []),
            $this->capability('advertisers', Schema::hasTable('advertiser_organizations')),
            $this->capability('rights_holders', Schema::hasTable('rights_holder_cases')),
            $this->capability('external_search', false),
            $this->capability('log_browser', false),
            $this->capability('feature_flags', false),
            $this->capability('browser_settings_editor', false),
        ];
    }

    private function capability(string $code, bool $installed): AdminCapabilityData
    {
        return new AdminCapabilityData(
            code: $code,
            label: __("administration.operations.capabilities.{$code}"),
            description: __("administration.operations.capability_descriptions.{$code}"),
            installed: $installed,
            statusLabel: $installed
                ? __('administration.operations.states.installed')
                : __('administration.operations.states.unavailable'),
        );
    }
}
