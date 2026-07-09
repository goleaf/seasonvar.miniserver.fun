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
        Schema::table('source_pages', function (Blueprint $table) {
            $table->string('import_status')->default('pending')->index();
            $table->json('missing_data_flags')->nullable();
            $table->timestamp('retry_after_at')->nullable()->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedBigInteger('last_import_run_id')->nullable()->index();
            $table->timestamp('last_imported_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_pages', function (Blueprint $table) {
            $table->dropColumn([
                'import_status',
                'missing_data_flags',
                'retry_after_at',
                'failure_count',
                'last_import_run_id',
                'last_imported_at',
            ]);
        });
    }
};
