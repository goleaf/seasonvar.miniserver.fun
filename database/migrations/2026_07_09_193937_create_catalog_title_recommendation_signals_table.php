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
        Schema::create('catalog_title_recommendation_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('signal_type', 64);
            $table->string('signal_key', 128);
            $table->string('signal_value')->nullable();
            $table->integer('weight')->default(0);
            $table->timestamp('observed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'source', 'signal_type', 'signal_key'], 'catalog_title_recommendation_signals_unique');
            $table->index(['signal_type', 'signal_key', 'weight'], 'catalog_title_recommendation_signals_lookup_idx');
            $table->index(['catalog_title_id', 'weight'], 'catalog_title_recommendation_signals_title_weight_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_recommendation_signals');
    }
};
