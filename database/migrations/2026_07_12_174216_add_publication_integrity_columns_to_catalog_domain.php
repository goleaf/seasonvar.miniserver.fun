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
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->string('publication_status', 20)->nullable();
            $table->string('audience', 20)->nullable();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->softDeletes();
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('publication_status', 20)->nullable();
            $table->string('audience', 20)->nullable();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->softDeletes();
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('publication_status', 20)->nullable();
            $table->string('audience', 20)->nullable();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->softDeletes();
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->string('audience', 20)->nullable();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['audience', 'available_from', 'available_until']);
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['kind', 'sort_order', 'publication_status', 'audience', 'available_from', 'available_until']);
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['kind', 'sort_order', 'publication_status', 'audience', 'available_from', 'available_until']);
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['publication_status', 'audience', 'available_from', 'available_until']);
        });
    }
};
