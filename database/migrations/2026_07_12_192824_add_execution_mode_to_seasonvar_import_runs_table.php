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
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->string('execution_mode', 16)->default('sync')->after('mode')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->dropIndex(['execution_mode']);
            $table->dropColumn('execution_mode');
        });
    }
};
