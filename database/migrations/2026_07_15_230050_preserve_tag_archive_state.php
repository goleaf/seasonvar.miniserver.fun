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
            $table->string('archived_from_visibility', 24)->nullable();
            $table->string('archived_from_moderation_status', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropColumn([
                'archived_from_visibility',
                'archived_from_moderation_status',
            ]);
        });
    }
};
