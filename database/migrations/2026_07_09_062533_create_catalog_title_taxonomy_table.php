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
        Schema::create('catalog_title_taxonomy', function (Blueprint $table) {
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained()->cascadeOnDelete();

            $table->primary(['catalog_title_id', 'taxonomy_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_taxonomy');
    }
};
