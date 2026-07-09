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
        Schema::create('source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64)->unique();
            $table->string('page_type')->default('unknown')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_hash', 64)->nullable()->index();
            $table->string('etag')->nullable();
            $table->string('last_modified_header')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->string('parse_status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->string('discovered_from_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['source_id', 'parse_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_pages');
    }
};
