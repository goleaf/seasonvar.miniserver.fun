<?php

declare(strict_types=1);

use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_title_reviews') || ! Schema::hasColumns('catalog_title_reviews', [
            'status',
            'deletion_reason',
            'deleted_by_id',
            'moderated_by_id',
            'moderated_at',
            'deleted_at',
            'updated_at',
            'created_at',
        ])) {
            return;
        }

        DB::table('catalog_title_reviews')
            ->where('status', ReviewStatus::Removed->value)
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('deletion_reason')
                    ->orWhereNull('deleted_by_id')
                    ->orWhereNull('deleted_at');
            })
            ->update([
                'deletion_reason' => DB::raw("COALESCE(deletion_reason, '".ReviewDeletionReason::Moderator->value."')"),
                'deleted_by_id' => DB::raw('COALESCE(deleted_by_id, moderated_by_id)'),
                'deleted_at' => DB::raw('COALESCE(deleted_at, moderated_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: rollback must not recreate invalid deletion evidence.
    }
};
