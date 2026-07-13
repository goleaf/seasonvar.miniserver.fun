<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('catalog_title_aliases', function (Blueprint $table): void {
            $table->dropUnique('catalog_title_aliases_catalog_title_id_type_name_hash_unique');
            $table->unique(
                ['catalog_title_id', 'name_hash'],
                'catalog_title_aliases_title_name_hash_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_title_aliases', function (Blueprint $table): void {
            $table->dropUnique('catalog_title_aliases_title_name_hash_unique');
            $table->unique(
                ['catalog_title_id', 'type', 'name_hash'],
                'catalog_title_aliases_catalog_title_id_type_name_hash_unique',
            );
        });
    }
};
