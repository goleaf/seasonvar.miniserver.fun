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
        Schema::create('catalog_title_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->decimal('rating', 4, 2)->nullable();
            $table->unsignedInteger('votes')->nullable();
            $table->string('raw_value')->nullable();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'provider']);
            $table->index(['provider', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_ratings');
    }
};
