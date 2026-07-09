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
        Schema::table('seasonvar_import_runs', function (Blueprint $table) {
            $table->unsignedInteger('process_id')->nullable()->after('forever')->index();
            $table->string('process_host')->nullable()->after('process_id');
            $table->text('process_command')->nullable()->after('process_host');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table) {
            $table->dropColumn(['process_id', 'process_host', 'process_command']);
        });
    }
};
