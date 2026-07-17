<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_schedule_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('logical_key', 191)->unique();
            $table->string('entry_type', 32);
            $table->string('status', 32)->default('scheduled');
            $table->string('precision', 24)->default('unknown');
            $table->string('source', 24)->default('unknown');
            $table->string('source_reference', 191)->nullable();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('licensed_media_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedInteger('episode_number')->nullable();
            $table->string('language_code', 16)->nullable();
            $table->string('translation_name', 120)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->date('date_value')->nullable();
            $table->date('date_end')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedTinyInteger('release_month')->nullable();
            $table->unsignedTinyInteger('release_quarter')->nullable();
            $table->string('original_timezone', 64)->default('UTC');
            $table->boolean('is_estimated')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_public')->default(true);
            $table->boolean('notifications_enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['is_public', 'status', 'starts_at', 'id'], 'release_schedule_public_time_idx');
            $table->index(['is_public', 'status', 'date_value', 'id'], 'release_schedule_public_date_idx');
            $table->index(['is_public', 'release_year', 'release_month', 'id'], 'release_schedule_public_partial_idx');
            $table->index(['catalog_title_id', 'status', 'starts_at', 'id'], 'release_schedule_title_time_idx');
            $table->index(['entry_type', 'status', 'starts_at', 'id'], 'release_schedule_type_time_idx');
            $table->index(['episode_id', 'entry_type', 'language_code'], 'release_schedule_episode_type_idx');
            $table->index(['licensed_media_id', 'entry_type'], 'release_schedule_media_type_idx');
        });

        Schema::create('release_schedule_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->timestamp('previous_starts_at')->nullable();
            $table->timestamp('new_starts_at')->nullable();
            $table->date('previous_date_value')->nullable();
            $table->date('new_date_value')->nullable();
            $table->date('previous_date_end')->nullable();
            $table->date('new_date_end')->nullable();
            $table->unsignedSmallInteger('previous_release_year')->nullable();
            $table->unsignedSmallInteger('new_release_year')->nullable();
            $table->unsignedTinyInteger('previous_release_month')->nullable();
            $table->unsignedTinyInteger('new_release_month')->nullable();
            $table->unsignedTinyInteger('previous_release_quarter')->nullable();
            $table->unsignedTinyInteger('new_release_quarter')->nullable();
            $table->string('previous_timezone', 64)->nullable();
            $table->string('new_timezone', 64);
            $table->string('previous_precision', 24)->nullable();
            $table->string('new_precision', 24);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->string('source', 24);
            $table->string('reason_code', 48)->nullable();
            $table->text('public_note')->nullable();
            $table->text('private_note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['release_schedule_entry_id', 'revision'], 'release_schedule_correction_revision_unique');
            $table->index(['release_schedule_entry_id', 'created_at', 'id'], 'release_schedule_correction_timeline_idx');
        });

        Schema::create('release_calendar_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->boolean('premiere_notifications')->default(true);
            $table->boolean('season_notifications')->default(true);
            $table->boolean('episode_notifications')->default(true);
            $table->boolean('translation_notifications')->default(true);
            $table->boolean('subtitle_notifications')->default(true);
            $table->boolean('portal_publication_notifications')->default(true);
            $table->boolean('date_change_notifications')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'catalog_title_id'], 'release_calendar_subscription_user_title_unique');
            $table->index(['catalog_title_id', 'user_id'], 'release_calendar_subscription_title_user_idx');
        });

        Schema::create('release_calendar_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('premiere_notifications')->default(true);
            $table->boolean('season_notifications')->default(true);
            $table->boolean('episode_notifications')->default(true);
            $table->boolean('translation_notifications')->default(true);
            $table->boolean('subtitle_notifications')->default(true);
            $table->boolean('date_change_notifications')->default(true);
            $table->boolean('postponed_notifications')->default(true);
            $table->boolean('cancelled_notifications')->default(true);
            $table->boolean('portal_publication_notifications')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_calendar_notification_preferences');
        Schema::dropIfExists('release_calendar_subscriptions');
        Schema::dropIfExists('release_schedule_corrections');
        Schema::dropIfExists('release_schedule_entries');
    }
};
