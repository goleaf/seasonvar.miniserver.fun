<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('media_sizes_checked')->default(0)->after('media_failed');
            $table->unsignedInteger('media_sizes_known')->default(0)->after('media_sizes_checked');
            $table->unsignedInteger('media_sizes_unknown')->default(0)->after('media_sizes_known');
            $table->unsignedInteger('media_sizes_unsupported')->default(0)->after('media_sizes_unknown');
            $table->unsignedInteger('media_size_checks_failed')->default(0)->after('media_sizes_unsupported');
            $table->unsignedBigInteger('media_size_known_bytes')->default(0)->after('media_size_checks_failed');
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'media_sizes_checked',
                'media_sizes_known',
                'media_sizes_unknown',
                'media_sizes_unsupported',
                'media_size_checks_failed',
                'media_size_known_bytes',
            ]);
        });
    }
};
