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
        Schema::create('catalog_title_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author')->nullable();
            $table->longText('body');
            $table->string('body_hash', 64);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'body_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_reviews');
    }
};
