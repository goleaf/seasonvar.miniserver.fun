<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->boolean('file_size_eligible')->default(false)->after('file_size_check_error');
            $table->timestamp('file_size_next_check_at')->nullable()->after('file_size_eligible');
            $table->index(
                ['file_size_eligible', 'file_size_next_check_at', 'id'],
                'licensed_media_file_size_schedule_idx',
            );
        });

        Schema::create('licensed_media_file_size_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('projection_cursor_id')->default(0);
            $table->timestamp('projection_completed_at')->nullable();
            $table->unsignedBigInteger('eligible')->default(0);
            $table->unsignedBigInteger('checked')->default(0);
            $table->unsignedBigInteger('pending')->default(0);
            $table->unsignedBigInteger('due')->default(0);
            $table->unsignedBigInteger('known')->default(0);
            $table->unsignedBigInteger('unknown')->default(0);
            $table->unsignedBigInteger('unsupported')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->unsignedBigInteger('known_bytes')->default(0);
            $table->timestamp('snapshot_captured_at')->nullable();
            $table->timestamps();
        });

        DB::table('licensed_media_file_size_state')->insert([
            'id' => 1,
            'projection_cursor_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('licensed_media_file_size_state');

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_file_size_schedule_idx');
            $table->dropColumn([
                'file_size_eligible',
                'file_size_next_check_at',
            ]);
        });
    }
};
