<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->binary('payload_blob')->nullable();
            $table->string('payload_codec', 32)->nullable();
            $table->unsignedInteger('payload_uncompressed_bytes')->nullable();
            $table->json('application_result')->nullable();
        });

        Schema::table('source_page_snapshots', function (Blueprint $table): void {
            $table->binary('html_blob')->nullable();
            $table->string('html_codec', 32)->nullable();
            $table->unsignedInteger('html_uncompressed_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('source_page_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'html_blob',
                'html_codec',
                'html_uncompressed_bytes',
            ]);
        });

        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'payload_blob',
                'payload_codec',
                'payload_uncompressed_bytes',
                'application_result',
            ]);
        });
    }
};
