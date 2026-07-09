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
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number')->default(1);
            $table->string('title')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('source_url_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
