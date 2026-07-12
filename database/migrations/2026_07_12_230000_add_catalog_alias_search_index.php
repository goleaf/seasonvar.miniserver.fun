<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_aliases', function (Blueprint $table): void {
            $table->index(['name_hash', 'catalog_title_id'], 'catalog_title_aliases_name_hash_title_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_aliases', function (Blueprint $table): void {
            $table->dropIndex('catalog_title_aliases_name_hash_title_idx');
        });
    }
};
