<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('seasonvar_import_prepared_pages')
            ->select([
                'seasonvar_import_run_id',
                'source_page_id',
            ])
            ->groupBy(
                'seasonvar_import_run_id',
                'source_page_id',
            )
            ->havingRaw('COUNT(*) > 1')
            ->exists()
        ) {
            throw new LogicException(
                'Нельзя включить unique run/page ledger: найдены повторяющиеся prepared rows.',
            );
        }

        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->timestamp('last_progress_at')->nullable()->index();
        });

        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->timestamp('last_enqueue_attempt_at')->nullable();
            $table->unsignedInteger('enqueue_attempts')->default(0);
            $table->unique(
                ['seasonvar_import_run_id', 'source_page_id'],
                'seasonvar_prepared_run_source_unique',
            );
            $table->index(
                [
                    'seasonvar_import_run_id',
                    'status',
                    'last_enqueue_attempt_at',
                    'id',
                ],
                'seasonvar_prepared_outbox_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->dropIndex('seasonvar_prepared_outbox_due_idx');
            $table->dropUnique('seasonvar_prepared_run_source_unique');
        });

        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'last_enqueue_attempt_at',
                'enqueue_attempts',
            ]);
        });

        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->dropIndex('seasonvar_import_runs_last_progress_at_index');
            $table->dropColumn('last_progress_at');
        });
    }
};
