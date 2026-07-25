<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_calendar_feeds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_collection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 32);
            $table->char('token_hash', 64)->unique();
            $table->text('token_secret');
            $table->string('language_code', 16)->nullable();
            $table->string('translation_name', 120)->nullable();
            $table->string('locale', 12);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('token_rotated_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id'], 'release_calendar_feeds_owner_order_idx');
        });

        Schema::table('release_schedule_entries', function (Blueprint $table): void {
            $table->index(['is_public', 'starts_at', 'id'], 'release_schedule_feed_time_idx');
            $table->index(['is_public', 'date_value', 'id', 'date_end'], 'release_schedule_feed_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('release_schedule_entries', function (Blueprint $table): void {
            $table->dropIndex('release_schedule_feed_time_idx');
            $table->dropIndex('release_schedule_feed_date_idx');
        });

        Schema::dropIfExists('release_calendar_feeds');
    }
};
