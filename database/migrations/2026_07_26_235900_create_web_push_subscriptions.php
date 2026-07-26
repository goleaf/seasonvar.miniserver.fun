<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->char('installation_hash', 64);
            $table->string('locale', 12);
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'web_push_public_id_unique');
            $table->unique('endpoint_hash', 'web_push_endpoint_hash_unique');
            $table->unique(
                ['user_id', 'installation_hash'],
                'web_push_user_installation_unique',
            );
            $table->index(
                ['user_id', 'disabled_at', 'id'],
                'web_push_user_delivery_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
