<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->string('mode', 16)->default('manual')->after('type');
            $table->json('smart_rules')->nullable()->after('sort_mode');
            $table->unsignedInteger('smart_rules_version')->default(1)->after('smart_rules');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->dropColumn([
                'mode',
                'smart_rules',
                'smart_rules_version',
            ]);
        });
    }
};
