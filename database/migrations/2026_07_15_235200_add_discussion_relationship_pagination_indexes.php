<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_blocks', function (Blueprint $table): void {
            $table->index(['blocker_id', 'id'], 'user_blocks_owner_page_idx');
        });

        Schema::table('user_mutes', function (Blueprint $table): void {
            $table->index(['muter_id', 'id'], 'user_mutes_owner_page_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_blocks', function (Blueprint $table): void {
            $table->dropIndex('user_blocks_owner_page_idx');
        });

        Schema::table('user_mutes', function (Blueprint $table): void {
            $table->dropIndex('user_mutes_owner_page_idx');
        });
    }
};
