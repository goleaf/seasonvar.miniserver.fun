<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_requests', function (Blueprint $table): void {
            $table->string('correction_target_key', 191)->nullable()->after('correction_field');
            $table->string('correction_reason', 32)->nullable()->after('correction_target_key');
        });
    }

    public function down(): void
    {
        Schema::table('content_requests', function (Blueprint $table): void {
            $table->dropColumn(['correction_target_key', 'correction_reason']);
        });
    }
};
