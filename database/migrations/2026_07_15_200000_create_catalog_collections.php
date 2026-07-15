<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
        });

        DB::table('users')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'public_id' => (string) Str::uuid(),
                    ]);
                }
            });

        Schema::create('catalog_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('slug', 180)->unique();
            $table->string('type', 24)->default('user');
            $table->string('visibility', 24)->default('private');
            $table->string('moderation_status', 24)->default('approved');
            $table->string('sort_mode', 32)->default('manual');
            $table->string('content_locale', 12)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('cover_disk', 64)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->string('cover_mime_type', 96)->nullable();
            $table->unsignedBigInteger('cover_size')->nullable();
            $table->unsignedBigInteger('cover_version')->default(0);
            $table->unsignedBigInteger('content_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'deleted_at', 'updated_at', 'id'], 'catalog_collections_owner_order_idx');
            $table->index(['visibility', 'moderation_status', 'deleted_at', 'updated_at', 'id'], 'catalog_collections_public_order_idx');
            $table->index(['type', 'is_featured', 'visibility', 'moderation_status'], 'catalog_collections_featured_idx');
        });

        Schema::create('catalog_collection_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 180)->unique();
            $table->timestamps();

            $table->index(['catalog_collection_id', 'created_at'], 'catalog_collection_slugs_collection_idx');
        });

        Schema::create('catalog_collection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['catalog_collection_id', 'catalog_title_id'], 'catalog_collection_items_collection_title_unique');
            $table->index(['catalog_collection_id', 'position', 'id'], 'catalog_collection_items_manual_order_idx');
            $table->index(['catalog_title_id', 'catalog_collection_id'], 'catalog_collection_items_title_lookup_idx');
        });

        Schema::create('catalog_collection_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('collection_public_id');
            $table->unsignedBigInteger('collection_content_version');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->text('resolution_note')->nullable();
            $table->char('deduplication_key', 64)->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['catalog_collection_id', 'status', 'created_at'], 'catalog_collection_reports_collection_status_idx');
            $table->index(['collection_public_id', 'created_at'], 'catalog_collection_reports_public_identity_idx');
            $table->index(['status', 'created_at', 'id'], 'catalog_collection_reports_moderation_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_collection_reports');
        Schema::dropIfExists('catalog_collection_items');
        Schema::dropIfExists('catalog_collection_slugs');
        Schema::dropIfExists('catalog_collections');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_public_id_unique');
            $table->dropColumn('public_id');
        });
    }
};
