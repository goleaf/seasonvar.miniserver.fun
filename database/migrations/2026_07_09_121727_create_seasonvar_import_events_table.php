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
        Schema::create('seasonvar_import_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seasonvar_import_run_id')->nullable()->index();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->index();
            $table->string('level', 16)->default('info')->index();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasonvar_import_events');
    }
};
