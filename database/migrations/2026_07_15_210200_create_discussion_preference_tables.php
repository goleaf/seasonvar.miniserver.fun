<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id'], 'user_blocks_direction_unique');
            $table->index(['blocked_id', 'blocker_id'], 'user_blocks_reverse_idx');
        });

        Schema::create('user_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('muter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('muted_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['muter_id', 'muted_id'], 'user_mutes_direction_unique');
            $table->index(['muted_id', 'muter_id'], 'user_mutes_reverse_idx');
        });

        Schema::create('comment_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('reply_notifications')->default(true);
            $table->boolean('reaction_notifications')->default(true);
            $table->boolean('moderation_notifications')->default(true);
            $table->boolean('report_notifications')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_notification_preferences');
        Schema::dropIfExists('user_mutes');
        Schema::dropIfExists('user_blocks');
    }
};
