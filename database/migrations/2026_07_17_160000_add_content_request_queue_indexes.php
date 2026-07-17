<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_requests', function (Blueprint $table): void {
            $table->index(
                ['requester_id', 'updated_at', 'id'],
                'content_requests_requester_updated_idx',
            );
            $table->index(
                ['requester_id', 'status', 'updated_at', 'id'],
                'content_requests_requester_status_updated_idx',
            );
            $table->index(
                ['status', 'created_at', 'id'],
                'content_requests_status_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_requests', function (Blueprint $table): void {
            $table->dropIndex('content_requests_requester_updated_idx');
            $table->dropIndex('content_requests_requester_status_updated_idx');
            $table->dropIndex('content_requests_status_created_idx');
        });
    }
};
