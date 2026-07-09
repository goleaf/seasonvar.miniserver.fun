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
        Schema::create('catalog_title_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_hash', 64);
            $table->string('type', 32)->default('alternative');
            $table->string('source', 64)->nullable();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'type', 'name_hash']);
            $table->index(['type', 'name_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_aliases');
    }
};
