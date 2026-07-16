<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->unsignedBigInteger('file_size_bytes')->nullable()->after('format');
            $table->timestamp('file_size_checked_at')->nullable()->after('file_size_bytes');
            $table->string('file_size_check_status', 24)->nullable()->after('file_size_checked_at');
            $table->string('file_size_source', 64)->nullable()->after('file_size_check_status');
            $table->unsignedSmallInteger('file_size_http_status')->nullable()->after('file_size_source');
            $table->string('file_size_check_error', 255)->nullable()->after('file_size_http_status');
            $table->index(
                ['file_size_check_status', 'file_size_checked_at', 'id'],
                'licensed_media_file_size_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_file_size_due_idx');
            $table->dropColumn([
                'file_size_bytes',
                'file_size_checked_at',
                'file_size_check_status',
                'file_size_source',
                'file_size_http_status',
                'file_size_check_error',
            ]);
        });
    }
};
