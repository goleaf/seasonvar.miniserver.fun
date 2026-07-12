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
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->string('import_claim_token', 64)->nullable();
            $table->timestamp('import_claimed_at')->nullable();
            $table->timestamp('import_claim_expires_at')->nullable();
            $table->foreignId('import_claim_run_id')
                ->nullable()
                ->constrained('seasonvar_import_runs')
                ->nullOnDelete();
            $table->index(
                ['page_type', 'import_claim_expires_at', 'id'],
                'source_pages_parallel_import_candidates_index',
            );
            $table->index(
                ['import_claim_run_id', 'import_claim_token'],
                'source_pages_parallel_import_run_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->dropIndex('source_pages_parallel_import_candidates_index');
            $table->dropIndex('source_pages_parallel_import_run_index');
            $table->dropConstrainedForeignId('import_claim_run_id');
            $table->dropColumn([
                'import_claim_token',
                'import_claimed_at',
                'import_claim_expires_at',
            ]);
        });
    }
};
