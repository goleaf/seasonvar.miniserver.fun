<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'type', 'created_at', 'id'],
                'notifications_recipient_list_idx',
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'type', 'read_at'],
                'notifications_recipient_unread_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
