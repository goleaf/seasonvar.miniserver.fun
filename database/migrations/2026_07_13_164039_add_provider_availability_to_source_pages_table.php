<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->string('provider_availability_status', 32)->nullable()->after('import_status');
            $table->timestamp('provider_availability_checked_at')->nullable()->after('provider_availability_status');
            $table->index(
                ['provider_availability_status', 'retry_after_at', 'id'],
                'source_pages_provider_availability_retry_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->dropIndex('source_pages_provider_availability_retry_idx');
            $table->dropColumn([
                'provider_availability_status',
                'provider_availability_checked_at',
            ]);
        });
    }
};
