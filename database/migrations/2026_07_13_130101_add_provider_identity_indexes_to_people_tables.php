<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->index('source_url', 'actors_source_url_index');
        });

        Schema::table('directors', function (Blueprint $table): void {
            $table->index('source_url', 'directors_source_url_index');
        });
    }

    public function down(): void
    {
        Schema::table('directors', function (Blueprint $table): void {
            $table->dropIndex('directors_source_url_index');
        });

        Schema::table('actors', function (Blueprint $table): void {
            $table->dropIndex('actors_source_url_index');
        });
    }
};
