<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropIndex('tags_public_eligibility_idx');
            $table->index(
                ['visibility', 'moderation_status', 'archived_at', 'merged_into_id', 'name', 'id'],
                'tags_public_eligibility_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropIndex('tags_public_eligibility_idx');
            $table->index(
                ['type', 'visibility', 'moderation_status', 'archived_at', 'id'],
                'tags_public_eligibility_idx',
            );
        });
    }
};
