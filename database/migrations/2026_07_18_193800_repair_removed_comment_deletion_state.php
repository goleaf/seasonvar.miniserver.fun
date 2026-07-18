<?php

declare(strict_types=1);

use App\Enums\CommentDeletionReason;
use App\Enums\CommentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comments') || ! Schema::hasColumns('comments', [
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

        DB::table('comments')
            ->where('status', CommentStatus::Removed->value)
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('deletion_reason')
                    ->orWhereNull('deleted_at');
            })
            ->update([
                'deletion_reason' => DB::raw("COALESCE(deletion_reason, '".CommentDeletionReason::Moderator->value."')"),
                'deleted_by_id' => DB::raw('COALESCE(deleted_by_id, moderated_by_id)'),
                'deleted_at' => DB::raw('COALESCE(deleted_at, moderated_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: rollback must not recreate structurally invalid deletion evidence.
    }
};
