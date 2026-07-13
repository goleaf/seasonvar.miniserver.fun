<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->json('provider_field_values')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropColumn('provider_field_values');
        });
    }
};
