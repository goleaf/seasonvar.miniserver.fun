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
        Schema::create('source_page_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('seasonvar_import_run_id')->nullable()->index();
            $table->string('url', 2048);
            $table->string('content_hash', 64);
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('body_bytes')->default(0);
            $table->longText('html');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['source_page_id', 'content_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_page_snapshots');
    }
};
