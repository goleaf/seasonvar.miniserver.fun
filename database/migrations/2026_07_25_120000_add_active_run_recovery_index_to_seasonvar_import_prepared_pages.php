<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->index(
                [
                    'seasonvar_import_run_id',
                    'status',
                    'updated_at',
                    'id',
                ],
                'seasonvar_prepared_run_status_updated_id_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->dropIndex(
                'seasonvar_prepared_run_status_updated_id_idx',
            );
        });
    }
};
