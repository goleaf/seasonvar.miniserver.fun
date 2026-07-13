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
        Schema::create('catalog_relation_source_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('relation_type', 32);
            $table->string('source_key_hash', 64);
            $table->string('canonical_key');
            $table->timestamps();
            $table->unique(
                ['source_id', 'relation_type', 'source_key_hash'],
                'catalog_relation_source_identity_unique',
            );
            $table->index(
                ['relation_type', 'canonical_key'],
                'catalog_relation_source_identity_canonical_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_relation_source_identities');
    }
};
