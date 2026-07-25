<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->dropColumn([
                'cover_disk',
                'cover_path',
                'cover_mime_type',
                'cover_size',
                'cover_version',
            ]);
        });

        Schema::table('catalog_collection_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'cover_source_path',
                'cover_path',
                'cover_content_hash',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->string('cover_disk', 64)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->string('cover_mime_type', 96)->nullable();
            $table->unsignedBigInteger('cover_size')->nullable();
            $table->unsignedBigInteger('cover_version')->default(0);
        });

        Schema::table('catalog_collection_sources', function (Blueprint $table): void {
            $table->string('cover_source_path', 512)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->char('cover_content_hash', 64)->nullable();
        });
    }
};
