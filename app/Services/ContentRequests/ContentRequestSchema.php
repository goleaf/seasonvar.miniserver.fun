<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use Illuminate\Support\Facades\Schema;

final class ContentRequestSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= collect([
            'content_requests',
            'content_request_votes',
            'content_request_followers',
            'content_request_status_histories',
            'content_request_source_links',
            'content_request_external_identifiers',
            'content_request_clarifications',
            'content_request_notification_preferences',
            'notifications',
        ])->every(fn (string $table): bool => Schema::hasTable($table));
    }
}
