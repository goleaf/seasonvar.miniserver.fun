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
        Schema::create('seasonvar_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 32)->default('all')->index();
            $table->string('status', 32)->default('running')->index();
            $table->string('argument', 2048)->nullable();
            $table->boolean('force')->default(false)->index();
            $table->boolean('forever')->default(false);
            $table->unsignedInteger('cycles')->default(0);
            $table->unsignedInteger('discovered')->default(0);
            $table->unsignedInteger('stored')->default(0);
            $table->unsignedInteger('selected')->default(0);
            $table->unsignedInteger('parsed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('media_attached')->default(0);
            $table->unsignedInteger('media_updated')->default(0);
            $table->unsignedInteger('media_skipped')->default(0);
            $table->unsignedInteger('media_failed')->default(0);
            $table->json('summary')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasonvar_import_runs');
    }
};
