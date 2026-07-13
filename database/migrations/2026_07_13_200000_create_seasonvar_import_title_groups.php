<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasonvar_import_title_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seasonvar_import_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_key_hash', 64);
            $table->string('queue_name', 128);
            $table->string('status', 32)->default('discovering');
            $table->unsignedInteger('expected_pages')->default(0);
            $table->unsignedInteger('prepared_pages')->default(0);
            $table->unsignedInteger('failed_pages')->default(0);
            $table->unsignedInteger('applied_pages')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['seasonvar_import_run_id', 'group_key_hash'],
                'seasonvar_import_title_groups_run_key_unique',
            );
            $table->index(
                ['catalog_title_id', 'status', 'id'],
                'seasonvar_import_title_groups_title_status_idx',
            );
        });

        Schema::create('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seasonvar_import_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seasonvar_import_title_group_id')
                ->constrained('seasonvar_import_title_groups')
                ->cascadeOnDelete();
            $table->foreignId('source_page_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('parser_version')->default(0);
            $table->json('payload')->nullable();
            $table->json('warnings')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['seasonvar_import_title_group_id', 'source_page_id'],
                'seasonvar_import_prepared_pages_group_page_unique',
            );
            $table->index(
                ['seasonvar_import_title_group_id', 'status', 'id'],
                'seasonvar_import_prepared_pages_group_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonvar_import_prepared_pages');
        Schema::dropIfExists('seasonvar_import_title_groups');
    }
};
