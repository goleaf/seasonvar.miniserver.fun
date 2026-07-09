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
        Schema::create('catalog_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('original_title')->nullable();
            $table->string('type')->default('serial')->index();
            $table->unsignedSmallInteger('year')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('poster_url', 2048)->nullable();
            $table->string('source_url', 2048);
            $table->string('source_url_hash', 64);
            $table->string('content_hash', 64)->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'source_url_hash']);
            $table->unique(['source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_titles');
    }
};
