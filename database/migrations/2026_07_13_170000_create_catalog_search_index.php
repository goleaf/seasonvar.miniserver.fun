<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_title_search_documents', function (Blueprint $table): void {
            $table->foreignId('catalog_title_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('title');
            $table->text('original_title')->default('');
            $table->text('aliases')->default('');
            $table->text('transliteration')->default('');
            $table->text('people')->default('');
            $table->text('taxonomies')->default('');
            $table->text('description')->default('');
            $table->text('suggestion_names')->default('');
            $table->string('normalized_title_key');
            $table->string('normalized_original_title_key')->default('');
            $table->text('normalized_alias_keys')->default('');
            $table->char('fingerprint', 64);
            $table->timestamps();
        });

        Schema::create('catalog_search_index_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('building');
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('document_count')->default(0);
            $table->unsignedBigInteger('checkpoint_id')->default(0);
            $table->timestamp('build_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE catalog_title_search_fts USING fts5(
                title,
                original_title,
                aliases,
                transliteration,
                people,
                taxonomies,
                description,
                content='catalog_title_search_documents',
                content_rowid='catalog_title_id',
                tokenize='unicode61 remove_diacritics 2'
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_title_search_documents_ai
            AFTER INSERT ON catalog_title_search_documents BEGIN
                INSERT INTO catalog_title_search_fts(
                    rowid, title, original_title, aliases, transliteration, people, taxonomies, description
                ) VALUES (
                    new.catalog_title_id, new.title, new.original_title, new.aliases,
                    new.transliteration, new.people, new.taxonomies, new.description
                );
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_title_search_documents_ad
            AFTER DELETE ON catalog_title_search_documents BEGIN
                INSERT INTO catalog_title_search_fts(
                    catalog_title_search_fts, rowid, title, original_title, aliases,
                    transliteration, people, taxonomies, description
                ) VALUES (
                    'delete', old.catalog_title_id, old.title, old.original_title, old.aliases,
                    old.transliteration, old.people, old.taxonomies, old.description
                );
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_title_search_documents_au
            AFTER UPDATE ON catalog_title_search_documents BEGIN
                INSERT INTO catalog_title_search_fts(
                    catalog_title_search_fts, rowid, title, original_title, aliases,
                    transliteration, people, taxonomies, description
                ) VALUES (
                    'delete', old.catalog_title_id, old.title, old.original_title, old.aliases,
                    old.transliteration, old.people, old.taxonomies, old.description
                );
                INSERT INTO catalog_title_search_fts(
                    rowid, title, original_title, aliases, transliteration, people, taxonomies, description
                ) VALUES (
                    new.catalog_title_id, new.title, new.original_title, new.aliases,
                    new.transliteration, new.people, new.taxonomies, new.description
                );
            END
            SQL);

        $timestamp = now();

        DB::table('catalog_search_index_states')->insert([
            'id' => 1,
            'version' => 1,
            'status' => 'building',
            'source_count' => 0,
            'document_count' => 0,
            'checkpoint_id' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS catalog_title_search_documents_au');
        DB::statement('DROP TRIGGER IF EXISTS catalog_title_search_documents_ad');
        DB::statement('DROP TRIGGER IF EXISTS catalog_title_search_documents_ai');
        DB::statement('DROP TABLE IF EXISTS catalog_title_search_fts');
        Schema::dropIfExists('catalog_search_index_states');
        Schema::dropIfExists('catalog_title_search_documents');
    }
};
