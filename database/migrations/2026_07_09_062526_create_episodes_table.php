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
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('source_url_hash', 64)->nullable();
            $table->date('released_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
