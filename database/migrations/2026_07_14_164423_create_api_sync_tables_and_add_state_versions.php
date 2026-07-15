<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('resource_type', 32);
            $table->string('resource_key', 512)->nullable();
            $table->string('operation', 16);
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['scope', 'id'], 'api_sync_changes_scope_cursor_idx');
            $table->index(['user_id', 'id'], 'api_sync_changes_user_cursor_idx');
            $table->index('changed_at', 'api_sync_changes_retention_idx');
        });

        Schema::create('api_sync_mutations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->char('payload_hash', 64);
            $table->string('status', 32);
            $table->json('result');
            $table->timestamps();

            $table->unique(['user_id', 'mutation_id'], 'api_sync_mutations_user_mutation_unique');
            $table->index('created_at', 'api_sync_mutations_retention_idx');
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('watchlist_version')->default(0);
            $table->unsignedBigInteger('rating_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropColumn(['watchlist_version', 'rating_version']);
        });

        Schema::dropIfExists('api_sync_mutations');
        Schema::dropIfExists('api_sync_changes');
    }
};
