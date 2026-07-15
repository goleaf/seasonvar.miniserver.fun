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
        Schema::table('tags', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable();
            $table->string('code', 120)->nullable();
            $table->string('type', 32)->default('imported');
            $table->string('visibility', 24)->default('public');
            $table->string('moderation_status', 24)->default('approved');
            $table->string('source', 24)->default('legacy');
            $table->string('normalized_name', 191)->nullable();
            $table->char('normalized_name_hash', 64)->nullable();
            $table->unsignedBigInteger('content_version')->default(1);
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->unique('public_id', 'tags_public_id_unique');
            $table->unique('code', 'tags_code_unique');
            $table->index('normalized_name_hash', 'tags_normalized_name_hash_idx');
            $table->index(['type', 'visibility', 'moderation_status', 'archived_at', 'id'], 'tags_public_eligibility_idx');
            $table->index('merged_into_id', 'tags_merged_into_idx');
        });

        $this->backfillTags();

        Schema::create('tag_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('label', 160);
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->text('seo_description')->nullable();
            $table->timestamps();

            $table->unique(['tag_id', 'locale'], 'tag_translations_tag_locale_unique');
            $table->index(['locale', 'label'], 'tag_translations_locale_label_idx');
        });

        Schema::create('tag_aliases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12)->default('und');
            $table->string('name', 160);
            $table->string('normalized_name', 191);
            $table->char('normalized_name_hash', 64);
            $table->string('slug', 180)->nullable()->unique();
            $table->string('source', 24)->default('editorial');
            $table->string('source_key', 191)->nullable();
            $table->string('moderation_status', 24)->default('approved');
            $table->timestamps();

            $table->unique(['locale', 'normalized_name_hash'], 'tag_aliases_locale_name_unique');
            $table->index(['tag_id', 'locale', 'moderation_status'], 'tag_aliases_tag_locale_status_idx');
        });

        Schema::create('tag_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->foreignId('related_tag_id')->constrained('tags')->restrictOnDelete();
            $table->string('relationship', 32)->default('related_search');
            $table->boolean('is_bidirectional')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();

            $table->unique(['tag_id', 'related_tag_id', 'relationship'], 'tag_synonyms_pair_relationship_unique');
            $table->index(['related_tag_id', 'relationship'], 'tag_synonyms_reverse_idx');
        });

        Schema::create('tag_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 180)->unique();
            $table->timestamps();

            $table->index(['tag_id', 'created_at'], 'tag_slugs_tag_created_idx');
        });

        Schema::create('tag_provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->char('provider_key', 64);
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('raw_label', 160);
            $table->string('normalized_name', 191);
            $table->char('normalized_name_hash', 64);
            $table->text('source_url')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_key'], 'tag_provider_mappings_provider_key_unique');
            $table->index(['tag_id', 'status'], 'tag_provider_mappings_tag_status_idx');
            $table->index(['normalized_name_hash', 'status'], 'tag_provider_mappings_name_status_idx');
        });

        Schema::create('catalog_title_tag_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('provider', 64)->nullable();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->char('source_key', 64);
            $table->boolean('is_current')->default(true);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['catalog_title_id', 'tag_id', 'source', 'source_key'], 'catalog_title_tag_sources_identity_unique');
            $table->index(['tag_id', 'catalog_title_id', 'is_current'], 'catalog_title_tag_sources_tag_title_idx');
            $table->index(['source_id', 'source_key'], 'catalog_title_tag_sources_provider_idx');
        });

        Schema::create('tag_merge_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_tag_id')->constrained('tags')->restrictOnDelete();
            $table->foreignId('target_tag_id')->constrained('tags')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamp('occurred_at');

            $table->index(['source_tag_id', 'occurred_at'], 'tag_merge_events_source_time_idx');
            $table->index(['target_tag_id', 'occurred_at'], 'tag_merge_events_target_time_idx');
        });

        Schema::create('user_tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 191);
            $table->char('normalized_name_hash', 64);
            $table->text('description')->nullable();
            $table->string('content_locale', 12)->nullable();
            $table->unsignedBigInteger('content_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'normalized_name_hash'], 'user_tags_owner_name_unique');
            $table->index(['user_id', 'deleted_at', 'updated_at', 'id'], 'user_tags_owner_order_idx');
        });

        Schema::create('catalog_title_user_tag', function (Blueprint $table): void {
            $table->foreignId('user_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->primary(['user_tag_id', 'catalog_title_id']);
            $table->index(['catalog_title_id', 'user_tag_id'], 'catalog_title_user_tag_title_idx');
            $table->index(['user_tag_id', 'position', 'catalog_title_id'], 'catalog_title_user_tag_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_title_user_tag');
        Schema::dropIfExists('user_tags');
        Schema::dropIfExists('tag_merge_events');
        Schema::dropIfExists('catalog_title_tag_sources');
        Schema::dropIfExists('tag_provider_mappings');
        Schema::dropIfExists('tag_slugs');
        Schema::dropIfExists('tag_synonyms');
        Schema::dropIfExists('tag_aliases');
        Schema::dropIfExists('tag_translations');

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_public_id_unique');
            $table->dropUnique('tags_code_unique');
            $table->dropIndex('tags_normalized_name_hash_idx');
            $table->dropIndex('tags_public_eligibility_idx');
            $table->dropIndex('tags_merged_into_idx');
            $table->dropColumn([
                'public_id',
                'code',
                'type',
                'visibility',
                'moderation_status',
                'source',
                'normalized_name',
                'normalized_name_hash',
                'content_version',
                'merged_into_id',
                'archived_at',
            ]);
        });
    }

    private function backfillTags(): void
    {
        DB::table('tags')->orderBy('id')->chunkById(250, function ($tags): void {
            foreach ($tags as $tag) {
                $normalized = $this->normalized((string) $tag->name);
                $isSubtitle = (string) $tag->slug === 'subtitry';

                DB::table('tags')->where('id', $tag->id)->update([
                    'public_id' => (string) Str::uuid(),
                    'code' => $isSubtitle ? 'subtitle-available' : null,
                    'type' => $isSubtitle ? 'system' : 'imported',
                    'visibility' => 'public',
                    'moderation_status' => 'approved',
                    'source' => $isSubtitle ? 'system' : ((string) ($tag->source_url ?? '') !== '' ? 'seasonvar' : 'legacy'),
                    'normalized_name' => $normalized,
                    'normalized_name_hash' => hash('sha256', $normalized),
                    'content_version' => 1,
                ]);
            }
        });
    }

    private function normalized(string $value): string
    {
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_KC)
            : $value;
        $value = $normalized === false ? $value : $normalized;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\p{Cf}\p{Cc}]+/u', '', $value) ?? '';
        $value = preg_replace('/\p{Pd}+/u', '-', $value) ?? $value;
        $value = preg_replace('/^\s*#+\s*/u', '', $value) ?? $value;

        return mb_strtolower(Str::squish($value));
    }
};
