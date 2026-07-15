<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_title_reviews')) {
            return;
        }

        $missingColumns = array_values(array_filter(
            [
                'original_body_hash',
                'status_before_merge',
                'deletion_reason_before_merge',
                'ownership_released_at',
            ],
            static fn (string $column): bool => ! Schema::hasColumn('catalog_title_reviews', $column),
        ));

        if ($missingColumns !== []) {
            Schema::table('catalog_title_reviews', function (Blueprint $table) use ($missingColumns): void {
                if (in_array('original_body_hash', $missingColumns, true)) {
                    $table->char('original_body_hash', 64)->nullable()->after('body_hash');
                }

                if (in_array('status_before_merge', $missingColumns, true)) {
                    $table->string('status_before_merge', 24)->nullable()->after('merged_into_id');
                }

                if (in_array('deletion_reason_before_merge', $missingColumns, true)) {
                    $table->string('deletion_reason_before_merge', 24)->nullable()->after('status_before_merge');
                }

                if (in_array('ownership_released_at', $missingColumns, true)) {
                    $table->timestamp('ownership_released_at')->nullable()->after('deletion_reason_before_merge');
                }
            });
        }

        if (Schema::hasTable('catalog_title_review_reports')
            && Schema::hasColumn('catalog_title_review_reports', 'deduplication_key')
            && ! $this->columnIsNullable('catalog_title_review_reports', 'deduplication_key')) {
            Schema::table('catalog_title_review_reports', function (Blueprint $table): void {
                $table->char('deduplication_key', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // This convergence migration only restores the schema already owned by 220000.
        // Its down migration removes these columns and tables when the whole domain rolls back.
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if (strcasecmp($definition['name'], $column) === 0) {
                return $definition['nullable'];
            }
        }

        return false;
    }
};
