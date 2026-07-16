# Full Portal Demo Seed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an idempotent, development-only Laravel seed pipeline that creates 100 complete demo users and natural Russian activity across exactly half of the published catalog per user, including library state, progress, reviews, comments, dialogues, tags, collections, requests, reports, technical support workflows, notifications, and audit coverage without duplicates.

**Architecture:** A deterministic hash-based core produces stable UUIDs, values, personas, timestamps, lexical fingerprints, and raster assets. Independent domain stages use existing Seasonvar tables, enums, normalizers, value-object rules, and bulk `upsert`/`insertOrIgnore` writes; a `PortalDemoSeeder` orchestrates the stages only in `dev|testing`, while a final auditor verifies counts, uniqueness, enum coverage, chronology, assets, and preservation of imported data.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent/query builder, SQLite, GD PNG generation, PHPUnit 12.5, Laravel Storage, Laravel Pint.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Preserve every unrelated staged, unstaged, and untracked change already present in the shared worktree.
- Do not add a production dependency or edit `.env`.
- Refuse the full seed before its first write unless the environment is exactly `dev` or `testing`.
- Never call `migrate:fresh`, `db:wipe`, `queue:clear`, `cache:clear`, or destructive catalog cleanup.
- Keep `admin@example.com`, `user@example.com`, imported titles, provider reviews, and all non-demo rows intact.
- Create exactly `user1@example.com` through `user100@example.com`, verified, with password `password` through the existing hashed cast.
- Select exactly `intdiv(published_titles_count, 2)` titles per demo user.
- Use deterministic keys and text; a second run with the same configuration must not increase target counts.
- Do not emit Lorem ipsum, placeholder prose, numeric uniqueness suffixes, real personal data, external HTTP requests, email, or push.
- Use Russian visible copy and existing enum/config values.
- Use chunked projections and bulk writes; do not load the complete multi-million-row corpus into memory.
- Run `./vendor/bin/pint --dirty --format agent` after PHP changes, focused tests first, then `php artisan test`.
- Check `README.md` on completion and update Russian visitor history only when the product-visible dev capability has changed.

---

## File Map

### Configuration and DTOs

- Create `config/demo-data.php`: full and test-tunable counts, density, chunk size, seed version, asset disk/path, and disk reserve.
- Create `app/DTOs/DemoData/DemoDataOptions.php`: validated immutable configuration.
- Create `app/DTOs/DemoData/DemoPersona.php`: identity and language-style profile for one user.
- Create `app/DTOs/DemoData/DemoTitleContext.php`: bounded catalog projection used by generators.
- Create `app/DTOs/DemoData/DemoStageReport.php`: stage counters and elapsed time.
- Create `app/DTOs/DemoData/DemoAuditReport.php`: final acceptance counters and violations.

### Deterministic core

- Create `app/Services/DemoData/DemoStableValue.php`: stable integers, booleans, choices, UUIDv5 values, hashes, and timestamps.
- Create `app/Services/DemoData/DemoPersonaFactory.php`: 100 unique Russian name/surname/username/persona combinations.
- Create `app/Services/DemoData/DemoLexicalFingerprint.php`: collision-free mixed-radix natural Russian clauses.
- Create `app/Services/DemoData/DemoRussianText.php`: all visible text generators.
- Create `app/Services/DemoData/DemoRasterAsset.php`: deterministic PNG bytes and private-storage metadata.
- Create `app/Services/DemoData/DemoTitleSelector.php`: exact half-catalog circular selection and bounded title/media projections.
- Create `app/Services/DemoData/DemoBulkWriter.php`: chunked upsert helpers with transactions and query-log suppression.

### Domain stages

- Create `app/Contracts/DemoDataStage.php`.
- Create `app/Services/DemoData/Stages/DemoAccountStage.php`.
- Create `app/Services/DemoData/Stages/DemoOrganizationStage.php`.
- Create `app/Services/DemoData/Stages/DemoCatalogActivityStage.php`.
- Create `app/Services/DemoData/Stages/DemoCommunityStage.php`.
- Create `app/Services/DemoData/Stages/DemoContentRequestStage.php`.
- Create `app/Services/DemoData/Stages/DemoModerationStage.php`.
- Create `app/Services/DemoData/Stages/DemoTechnicalIssueStage.php`.
- Create `app/Services/DemoData/Stages/DemoNotificationStage.php`.
- Create `app/Services/DemoData/DemoDataOrchestrator.php`.
- Create `app/Services/DemoData/DemoDataAuditor.php`.

### Seeder and documentation

- Create `database/seeders/PortalDemoSeeder.php`.
- Modify `database/seeders/DatabaseSeeder.php`.
- Modify `README.md`.
- Modify `CHANGELOG.md`.
- Modify the thematic owner selected by `docs/README.md` after re-reading its current map.

### Tests

- Create `tests/Unit/DemoData/DemoStableValueTest.php`.
- Create `tests/Unit/DemoData/DemoLexicalFingerprintTest.php`.
- Create `tests/Unit/DemoData/DemoRussianTextTest.php`.
- Create `tests/Unit/DemoData/DemoTitleSelectorTest.php`.
- Create `tests/Feature/DemoData/DemoAccountStageTest.php`.
- Create `tests/Feature/DemoData/DemoCatalogCorpusStageTest.php`.
- Create `tests/Feature/DemoData/DemoCommunityStageTest.php`.
- Create `tests/Feature/DemoData/DemoWorkflowStageTest.php`.
- Create `tests/Feature/DemoData/PortalDemoSeederTest.php`.

---

### Task 1: Deterministic core and validated options

**Files:**

- Create: `config/demo-data.php`
- Create: `app/DTOs/DemoData/DemoDataOptions.php`
- Create: `app/DTOs/DemoData/DemoPersona.php`
- Create: `app/DTOs/DemoData/DemoTitleContext.php`
- Create: `app/DTOs/DemoData/DemoStageReport.php`
- Create: `app/DTOs/DemoData/DemoAuditReport.php`
- Create: `app/Services/DemoData/DemoStableValue.php`
- Create: `app/Services/DemoData/DemoPersonaFactory.php`
- Create: `app/Services/DemoData/DemoLexicalFingerprint.php`
- Create: `app/Services/DemoData/DemoRussianText.php`
- Test: `tests/Unit/DemoData/DemoStableValueTest.php`
- Test: `tests/Unit/DemoData/DemoLexicalFingerprintTest.php`
- Test: `tests/Unit/DemoData/DemoRussianTextTest.php`

**Interfaces:**

- Produces: `DemoDataOptions::fromConfig(): DemoDataOptions` and `assertEnvironment(string $environment): void`.
- Produces: `DemoStableValue::integer(string $scope, int $minimum, int $maximum): int`, `boolean(string $scope, int $percentage): bool`, `pick(string $scope, array $values): mixed`, `uuid(string $scope): string`, `hash(string $scope): string`, `date(string $scope, CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable`.
- Produces: `DemoPersonaFactory::make(int $userIndex): DemoPersona` for indexes 1–100.
- Produces: `DemoLexicalFingerprint::clause(string $domain, int $ordinal): string` with collision-free output for at least 25 million ordinals per domain.
- Produces: `DemoRussianText` methods `biography`, `reviewTitle`, `reviewBody`, `commentBody`, `replyBody`, `personalTag`, `collection`, `request`, `report`, `technicalIssue`, and `supportReply`.

- [ ] **Step 1: Write failing deterministic-core tests**

```php
public function test_stable_values_and_uuid_are_repeatable(): void
{
    $values = new DemoStableValue('seasonvar-demo-v1');

    self::assertSame($values->integer('user:1', 1, 10), $values->integer('user:1', 1, 10));
    self::assertNotSame($values->hash('user:1'), $values->hash('user:2'));
    self::assertTrue(Str::isUuid($values->uuid('comment:user:1:title:1')));
}

public function test_environment_guard_rejects_production(): void
{
    $this->expectException(LogicException::class);
    DemoDataOptions::fromConfig()->assertEnvironment('production');
}

public function test_lexical_fingerprints_are_unique_and_natural(): void
{
    $generator = new DemoLexicalFingerprint;
    $clauses = [];

    foreach (range(0, 49_999) as $ordinal) {
        $clause = $generator->clause('review', $ordinal);
        self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $clause);
        self::assertDoesNotMatchRegularExpression('/\b\d+\b/u', $clause);
        $clauses[] = $clause;
    }

    self::assertCount(50_000, array_unique($clauses));
}
```

- [ ] **Step 2: Run the tests and confirm the missing-class failures**

Run:

```bash
php artisan test tests/Unit/DemoData/DemoStableValueTest.php tests/Unit/DemoData/DemoLexicalFingerprintTest.php tests/Unit/DemoData/DemoRussianTextTest.php
```

Expected: FAIL because the new DTO and generator classes do not exist.

- [ ] **Step 3: Implement configuration and immutable DTOs**

`config/demo-data.php` must define exact defaults:

```php
return [
    'version' => 'seasonvar-demo-v1',
    'enabled' => true,
    'user_count' => 100,
    'coverage_numerator' => 1,
    'coverage_denominator' => 2,
    'chunk_size' => 1_000,
    'minimum_free_bytes' => 25 * 1024 * 1024 * 1024,
    'personal_tags' => ['minimum' => 12, 'maximum' => 40, 'per_title_minimum' => 2, 'per_title_maximum' => 7],
    'collections' => ['minimum' => 8, 'maximum' => 20, 'per_title_minimum' => 1, 'per_title_maximum' => 3],
    'requests' => ['minimum' => 3, 'maximum' => 10],
    'issues' => ['minimum' => 2, 'maximum' => 6],
    'notifications' => ['minimum' => 20, 'maximum' => 60],
    'public_tag_target' => 800,
    'asset_disk' => env('UPLOAD_DISK', 'uploads'),
    'asset_prefix' => 'demo-data/seasonvar-demo-v1',
];
```

`DemoDataOptions` validates positive counts, numerator smaller than denominator, chunk size 100–5000, and only `dev|testing` environments. DTO properties use exact scalar/list types and no mutable public arrays without PHPDoc element types.

- [ ] **Step 4: Implement stable hashing, UUIDv5, persona, fingerprint, and Russian text generation**

Use SHA-256 bytes for bounded integers and Ramsey UUIDv5 for stable UUIDs:

```php
public function integer(string $scope, int $minimum, int $maximum): int
{
    if ($maximum < $minimum) {
        throw new InvalidArgumentException('Maximum must be greater than or equal to minimum.');
    }

    $value = hexdec(substr(hash('sha256', $this->namespace.'|'.$scope), 0, 12));

    return $minimum + ($value % ($maximum - $minimum + 1));
}

public function uuid(string $scope): string
{
    return Uuid::uuid5(Uuid::NAMESPACE_URL, $this->namespace.'|'.$scope)->toString();
}
```

`DemoLexicalFingerprint` uses four grammatical vocabularies of at least 80 entries each. Convert ordinal to four mixed-radix indexes and render a clause such as `Мне запомнились сдержанный ритм, внимательная интонация, цельная атмосфера и ясное послевкусие.` The tuple, not a visible number, guarantees uniqueness.

All `DemoRussianText` methods append one domain-specific lexical fingerprint, validate length against existing config, and use `ReviewBody::from()` or `CommentBody::from()` in tests to prove compatibility.

- [ ] **Step 5: Run unit tests**

Run the same three test files. Expected: PASS with no duplicate fingerprints, no digits used for uniqueness, valid UUIDs, and production refusal.

- [ ] **Step 6: Format and commit Task 1**

```bash
./vendor/bin/pint --dirty --format agent
git add config/demo-data.php app/DTOs/DemoData app/Services/DemoData/DemoStableValue.php app/Services/DemoData/DemoPersonaFactory.php app/Services/DemoData/DemoLexicalFingerprint.php app/Services/DemoData/DemoRussianText.php tests/Unit/DemoData
git commit -m "feat: add deterministic demo data core"
```

Use an exact-path commit so unrelated staged files are excluded.

---

### Task 2: Exact title selection, bulk writer, and deterministic PNG assets

**Files:**

- Create: `app/Services/DemoData/DemoTitleSelector.php`
- Create: `app/Services/DemoData/DemoBulkWriter.php`
- Create: `app/Services/DemoData/DemoRasterAsset.php`
- Test: `tests/Unit/DemoData/DemoTitleSelectorTest.php`

**Interfaces:**

- Consumes: `DemoDataOptions`, `DemoStableValue`, `DemoTitleContext`.
- Produces: `publishedCount(): int`, `selectedIds(int $userIndex): LazyCollection<int, int>`, `contexts(array $titleIds): Collection<int, DemoTitleContext>`.
- Produces: `DemoBulkWriter::upsert(string $table, array $rows, array $uniqueBy, array $update): int` in bounded chunks.
- Produces: `DemoRasterAsset::store(string $kind, string $identity, int $width, int $height): array{disk:string,path:string,mime_type:string,size:int,width:int,height:int,hash:string}`.

- [ ] **Step 1: Write failing selector and asset tests**

Create eight published title factories and two unpublished titles. Assert each of four user indexes receives exactly four IDs, repeated calls are equal, every published title appears for exactly two users, and unpublished IDs never appear. Use `Storage::fake('uploads')` and assert the same asset identity produces the same path/hash/bytes twice.

- [ ] **Step 2: Run focused tests**

```bash
php artisan test tests/Unit/DemoData/DemoTitleSelectorTest.php
```

Expected: FAIL because selector and asset classes are missing.

- [ ] **Step 3: Implement circular half selection and bounded projections**

Read published IDs once as an ordered lazy stream, cache only the integer vector for 32 926 IDs, and compute:

```php
$half = intdiv(count($ids), 2);
$offset = (($userIndex - 1) * max(1, intdiv(count($ids), $options->userCount))) % count($ids);

for ($position = 0; $position < $half; $position++) {
    yield $ids[($offset + $position) % count($ids)];
}
```

`contexts()` performs grouped queries for title, first season, first/last episode, first eligible licensed media, genre labels, and display title without per-title queries.

- [ ] **Step 4: Implement bulk writer and PNG storage**

`DemoBulkWriter` splits rows by configured chunk size, wraps each chunk in `DB::transaction(..., attempts: 3)`, disables the query log, and calls query-builder `upsert`. `DemoRasterAsset` creates GD true-color PNGs with deterministic HSL-derived colors, geometry, and initials, then stores to `config('demo-data.asset_prefix').'/'.$kind.'/'.$stableUuid.'.png'` on the private upload disk with private visibility.

- [ ] **Step 5: Run selector tests and storage assertions**

Expected: PASS; exact 50% balance and byte-identical assets.

- [ ] **Step 6: Format and commit Task 2**

```bash
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/DemoTitleSelector.php app/Services/DemoData/DemoBulkWriter.php app/Services/DemoData/DemoRasterAsset.php tests/Unit/DemoData/DemoTitleSelectorTest.php
git commit -m "feat: add balanced demo catalog selection"
```

---

### Task 3: Accounts, profiles, settings, preferences, and device rows

**Files:**

- Create: `app/Contracts/DemoDataStage.php`
- Create: `app/Services/DemoData/Stages/DemoAccountStage.php`
- Test: `tests/Feature/DemoData/DemoAccountStageTest.php`

**Interfaces:**

- Produces: `DemoDataStage::key(): string` and `run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport`.
- Produces 100 deterministic users plus `user_profiles`, `user_account_settings`, four notification-preference tables, and 1–3 `personal_access_tokens` per user.

- [ ] **Step 1: Write failing account-stage tests**

Override `demo-data.user_count` to 4. Run the stage twice and assert four `userN@example.com` rows, four unique names/usernames/public IDs, verified timestamps, `Hash::check('password', ...)`, complete profile/privacy/settings columns, all preference rows, 1–3 tokens each, valid stored PNG assets, and unchanged counts on the second run.

- [ ] **Step 2: Run the focused test**

```bash
php artisan test tests/Feature/DemoData/DemoAccountStageTest.php
```

Expected: FAIL because the stage contract and account stage are missing.

- [ ] **Step 3: Implement account upserts**

Use `updateOrCreate(['email' => "user{$index}@example.com"], [...])` only for the 100 user rows so the hashed password cast is preserved. Force deterministic `public_id`, historical `created_at`, verified timestamp, and normal Russian full name. Bulk-upsert profiles/settings/preferences by `user_id` after resolving IDs by email.

Set profile asset columns from `DemoRasterAsset`. Create Sanctum rows directly with token `hash('sha256', stable secret)`, names such as `Chrome · Windows`, abilities `['mobile:read','mobile:write']`, used/created/expires timestamps, and no recoverable plain token. Only create database session rows when `config('session.driver') === 'database'`.

- [ ] **Step 4: Run account tests twice**

Expected: PASS with exact idempotent counts and valid assets.

- [ ] **Step 5: Format and commit Task 3**

```bash
./vendor/bin/pint --dirty --format agent
git add app/Contracts/DemoDataStage.php app/Services/DemoData/Stages/DemoAccountStage.php tests/Feature/DemoData/DemoAccountStageTest.php
git commit -m "feat: seed complete demo accounts"
```

---

### Task 4: Personal tags, public tags, collections, and memberships

**Files:**

- Create: `app/Services/DemoData/Stages/DemoOrganizationStage.php`
- Test: `tests/Feature/DemoData/DemoCatalogCorpusStageTest.php`

**Interfaces:**

- Consumes: demo users, `DemoTitleSelector`, `DemoRussianText`, `DemoStableValue`, `TagNormalizationService`, `DemoRasterAsset`, `DemoBulkWriter`.
- Produces: 12–40 owner-scoped `user_tags`, 8–20 user collections, all selected title assignments, and 3–12 public tag links on exact half of the published catalog.

- [ ] **Step 1: Write failing organization-stage tests**

With four users/eight titles and overridden minima, assert unique normalized personal tags, 2–7 ordered assignments per selected title, 1–3 collection memberships per selected title, all collection visibilities and sort modes, private PNG covers, no duplicate pivots, and exactly four public-tagged titles. Run twice and compare table counts.

- [ ] **Step 2: Run the focused test**

Expected: FAIL because `DemoOrganizationStage` does not exist.

- [ ] **Step 3: Implement personal tags and assignments**

Use `TagNormalizationService::display/comparison/hash`, deterministic UUIDs, and owner/name hash upserts. Query inserted tag IDs by `(user_id, normalized_name_hash)` and bulk-upsert `catalog_title_user_tag` using `(user_tag_id, catalog_title_id)` with stable positions.

- [ ] **Step 4: Implement collections and memberships**

Generate stable UUID and globally unique slug `demo-{$versionHash}-{$userIndex}-{$collectionIndex}`; visible names remain natural Russian text. Upsert collection rows by `public_id`, store private cover PNGs, and resolve collection IDs. Partition selected title IDs by semantic tag/rating buckets and upsert `catalog_collection_items` by `(catalog_collection_id, catalog_title_id)`.

- [ ] **Step 5: Implement public tags and exact-half assignments**

Reuse eligible tags; if fewer than 800, generate and normalize additional `system/public/approved/manual` Russian tags with deterministic UUID/slug and `ru` translations. Select exact half of global published title IDs and insert 3–12 unique `catalog_title_tag` pairs per title.

- [ ] **Step 6: Run the stage test and commit**

```bash
php artisan test tests/Feature/DemoData/DemoCatalogCorpusStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoOrganizationStage.php tests/Feature/DemoData/DemoCatalogCorpusStageTest.php
git commit -m "feat: seed demo tags and collections"
```

---

### Task 5: Catalog state, rating, recommendations, progress, and history

**Files:**

- Create: `app/Services/DemoData/Stages/DemoCatalogActivityStage.php`
- Modify: `tests/Feature/DemoData/DemoCatalogCorpusStageTest.php`

**Interfaces:**

- Consumes: exact title selection and bounded episode/media contexts.
- Produces: one `catalog_title_user_states` row per selected pair and one eligible `episode_view_progress` row per selected pair with media.

- [ ] **Step 1: Add failing state/progress tests**

Assert exact half-state count per user, ratings 1–10, all four watch statuses, exactly 5%/2% feedback distribution within rounding rules, positive versions/timestamps, real foreign keys for episode/media, progress 0–100, `completed_at` consistency, and no duplicate `(user_id,title_id)` or `(user_id,episode_id)` rows.

- [ ] **Step 2: Run the focused test**

Expected: FAIL because state/progress stage is missing.

- [ ] **Step 3: Implement bulk state rows**

For each user, stream selected IDs in chunks. Derive status by stable percentile, rating by persona/title context, watchlist boolean, feedback at percentiles 0–4/5–6, and monotonically valid timestamps. Upsert on `(user_id,catalog_title_id)` and update only demo-owned state columns.

- [ ] **Step 4: Implement real episode/media progress**

For each context with episode/media, choose an episode from the bounded context. Planned rows have zero/early progress, watching/dropped rows have 5–89%, completed rows have 90–100% and `completed_at`. Use deterministic playback-session UUID and event sequence. Upsert on `(user_id,episode_id)`.

- [ ] **Step 5: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoCatalogCorpusStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoCatalogActivityStage.php tests/Feature/DemoData/DemoCatalogCorpusStageTest.php
git commit -m "feat: seed demo catalog activity"
```

---

### Task 6: Reviews, comments, dialogues, reactions, and community preferences

**Files:**

- Create: `app/Services/DemoData/Stages/DemoCommunityStage.php`
- Create: `tests/Feature/DemoData/DemoCommunityStageTest.php`

**Interfaces:**

- Consumes: users, title states, collection IDs, `ReviewIdentity`, `ReviewBody`, `CommentBody`, Russian text and stable UUIDs.
- Produces: one user review and one root comment per selected pair, collection discussions, replies for 25% of roots, review votes, comment reactions, statuses/edit/delete/restore representations, and no self-interaction.

- [ ] **Step 1: Write failing community tests**

Assert review/root-comment counts equal exact selected pair counts, bodies pass value-object validation, full-body hashes are unique by domain, review ownership/submission keys are unique, all moderation statuses occur in a configured small fixture, 25% roots have 2–8 replies, reply timestamps increase, 2–6 participants occur, reaction/vote uniqueness holds, and no author reacts/votes on their own row.

- [ ] **Step 2: Run focused test**

```bash
php artisan test tests/Feature/DemoData/DemoCommunityStageTest.php
```

Expected: FAIL because the community stage is missing.

- [ ] **Step 3: Implement reviews**

Use `ReviewIdentity::ownershipKey/submissionKey`, `ReviewBody::from`, `ReviewTitle::from`, and `ReviewOrigin::User`. Bulk-upsert on `ownership_key`. Map status by a weighted distribution with at least 94% published and explicit global coverage of pending/hidden/rejected/spam/removed. Represent edited/restored records with version/timestamps and 2% soft deletion. Query IDs by ownership key, then insert non-author helpful/not-helpful votes.

- [ ] **Step 4: Implement root comments and collection discussions**

Use deterministic UUID tokens transformed to the same SHA-256 `submission_key` contract as `CreateComment`; use `CommentBody::from` for body/hash. Target title/season/episode according to available context and collection for collection discussions. Upsert by submission key, with weighted statuses, versions, moderation fields, and 2% soft deletion.

- [ ] **Step 5: Implement dialogues and reactions**

For every fourth root, pick 2–6 non-blocked participants by circular user index and generate 2–8 replies. Set `parent_id` to root and `reply_to_id` to the immediately previous reply. Each reply text receives its own collision-free ordinal and semantic response kind. Upsert by stable submission key, then bulk-insert 1–4 non-author reactions per eligible comment.

- [ ] **Step 6: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoCommunityStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoCommunityStage.php tests/Feature/DemoData/DemoCommunityStageTest.php
git commit -m "feat: seed demo community discussions"
```

---

### Task 7: Content requests, links, identifiers, votes, follows, clarifications, and histories

**Files:**

- Create: `app/Services/DemoData/Stages/DemoContentRequestStage.php`
- Create: `tests/Feature/DemoData/DemoWorkflowStageTest.php`

**Interfaces:**

- Consumes: current content-request enums/config, title/season/episode contexts, `ContentRequestIdentity`, Russian text, admin user.
- Produces: 3–10 requests per user with every type/status/correction field, source links, external IDs, votes, followers, clarification dialogue, status history, merge/completion references.

- [ ] **Step 1: Write failing request workflow tests**

Assert per-user bounds, all ten request types, all thirteen statuses, all correction fields, valid target foreign keys, stable public/submission/identity keys, 1–3 safe HTTPS links, external providers from the enum, requester vote/follow plus other users, chronological clarifications/status history, and idempotent rerun counts.

- [ ] **Step 2: Run focused workflow test**

Expected: FAIL because the request stage is missing.

- [ ] **Step 3: Implement request rows and type-specific fields**

Create a deterministic round-robin matrix over `ContentRequestType::cases()` and `ContentRequestStatus::cases()`. Fill only fields allowed by the existing type rules, derive normalized title/hash through `ContentRequestIdentity`, generate unique active identity only for active statuses, and use stable UUID/public/submission keys. Completed/merged rows receive valid canonical target references and timestamps.

- [ ] **Step 4: Implement child rows**

Upsert source links by stable URL hash, external IDs by `(request,provider,identifier)`, votes/follows by `(request,user)`, clarifications by stable submission key, and status histories by idempotency key. Alternate requester/admin authors and maintain increasing timestamps.

- [ ] **Step 5: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoWorkflowStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoContentRequestStage.php tests/Feature/DemoData/DemoWorkflowStageTest.php
git commit -m "feat: seed demo content requests"
```

---

### Task 8: Reports, restrictions, blocks, and mutes

**Files:**

- Create: `app/Services/DemoData/Stages/DemoModerationStage.php`
- Modify: `tests/Feature/DemoData/DemoWorkflowStageTest.php`

**Interfaces:**

- Consumes: seeded comments, reviews, public collections/profiles, admin user, report enums, Russian report text.
- Produces per user: four comment reports, four review reports, two collection reports, one profile report, two blocks, two mutes, comment/review restrictions, resolutions, and moderator notes.

- [ ] **Step 1: Add failing moderation assertions**

Assert exact per-user report counts, global coverage of every report category/status, no self-report, unique deduplication keys, moderator/resolved fields for closed outcomes, restriction enum coverage, two unique blocks and mutes per user with disjoint targets, and stable counts after rerun.

- [ ] **Step 2: Run focused workflow test**

Expected: FAIL because moderation stage is missing.

- [ ] **Step 3: Implement reports and restrictions**

Choose reportable rows by circular offset excluding the reporter. Generate report key using existing identity services where available and SHA-256 of domain/reporter/target/category elsewhere. Upsert comment/review/collection/profile reports by deduplication key. Cycle open/reviewed/resolved/dismissed according to the domain enum, populate admin moderator and chronological resolution fields, and create bounded temporary/permanent restrictions without restricting every demo user simultaneously.

- [ ] **Step 4: Implement relationship state**

For user index `n`, block `(n+17) mod userCount` and `(n+31)`, mute `(n+43)` and `(n+59)`, normalized to 1-based indexes. Upsert by directional unique keys and timestamp them after the last shared seeded dialogue.

- [ ] **Step 5: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoWorkflowStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoModerationStage.php tests/Feature/DemoData/DemoWorkflowStageTest.php
git commit -m "feat: seed demo moderation workflows"
```

---

### Task 9: All technical issue types, diagnostics, attachments, support dialogue, and resolution workflow

**Files:**

- Create: `app/Services/DemoData/Stages/DemoTechnicalIssueStage.php`
- Modify: `tests/Feature/DemoData/DemoWorkflowStageTest.php`

**Interfaces:**

- Consumes: every technical-issue enum, title/media contexts, Russian text, raster assets, admin user.
- Produces: 2–6 issues per user, all 45 types, all target/status/severity/priority/resolution values, diagnostics, attachments, confirmations, followers, occurrences, messages, assignments, histories, merge/reopen/verify outcomes.

- [ ] **Step 1: Add failing technical issue assertions**

Assert all 45 `TechnicalIssueType` values, every target/status/severity/priority/resolution enum, per-user bounds, stable unique public number/public/submission/identity keys, valid nullable target hierarchy, diagnostics-consent consistency, 1–3 PNGs on half the issues, confirmation/follower non-self rows, public/internal messages, chronological histories, merged canonical links, reopen count, and no duplicate child unique keys.

- [ ] **Step 2: Run focused workflow test**

Expected: FAIL because technical issue stage is missing.

- [ ] **Step 3: Implement issue roots and diagnostics**

Round-robin every enum case before reusing a type. Build public number `ISS-` plus the first 20 uppercase stable hash characters. Set target fields only when real title/season/episode/media records exist; page/account/search/general issues use safe internal route snapshots. Upsert issues by submission key and diagnostics by issue ID.

- [ ] **Step 4: Implement attachments and engagement**

Store deterministic PNGs under the demo asset prefix and upsert attachment rows using stable UUID/content hash. Insert 1–4 non-requester confirmations/followers/occurrences with diagnostics that contain no IP, secret, URL, or raw log.

- [ ] **Step 5: Implement support messages and lifecycle**

Create alternating requester-visible messages, admin support replies, and admin-only internal notes. Upsert messages by stable submission key, attachments by content hash, assignments and status history by idempotency key. Populate resolution/rejection/merge/reopen/verification timestamps consistently with final status.

- [ ] **Step 6: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoWorkflowStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoTechnicalIssueStage.php tests/Feature/DemoData/DemoWorkflowStageTest.php
git commit -m "feat: seed demo technical support workflows"
```

---

### Task 10: Database notifications and offline sync receipts

**Files:**

- Create: `app/Services/DemoData/Stages/DemoNotificationStage.php`
- Modify: `tests/Feature/DemoData/DemoWorkflowStageTest.php`

**Interfaces:**

- Consumes: existing activity notification classes/enums and `ApiSyncMutationService`.
- Produces: 20–60 read/unread notifications per user and deterministic applied/duplicate/conflict/rejected/not_found sync outcomes without outbound channels.

- [ ] **Step 1: Add failing notification/sync assertions**

Assert every activity notification kind, 20–60 rows per user, valid existing target IDs/public IDs, both read/unread timestamps, no private notes or URLs in JSON payload, stable UUID IDs, one persisted receipt for each applicable sync status, and duplicate replay behavior from the same mutation ID.

- [ ] **Step 2: Run focused workflow test**

Expected: FAIL because notification stage is missing.

- [ ] **Step 3: Implement database notification rows**

Instantiate existing `CommentActivityNotification`, `ReviewActivityNotification`, `ContentRequestActivityNotification`, and `TechnicalIssueActivityNotification`, call `databaseType()`/`toDatabase()` for safe payloads, and bulk-upsert `notifications` by deterministic UUID. Do not call `notify()` or queue a transport. Set `read_at` for a stable 55% of rows.

- [ ] **Step 4: Implement sync receipts through the domain service**

Call `ApiSyncMutationService::apply()` with stable mutation IDs and valid owner data for applied and duplicate replay, a stale expected version for conflict, nonexistent resource for not_found, and invalid progress/session context for rejected. Do not invoke `history.clear` against the final corpus. Assert replay is duplicate and rows remain owner-scoped.

- [ ] **Step 5: Run, format, and commit**

```bash
php artisan test tests/Feature/DemoData/DemoWorkflowStageTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/Stages/DemoNotificationStage.php tests/Feature/DemoData/DemoWorkflowStageTest.php
git commit -m "feat: seed demo notifications and sync receipts"
```

---

### Task 11: Orchestrator, environment/disk guards, audit, and DatabaseSeeder integration

**Files:**

- Create: `app/Services/DemoData/DemoDataOrchestrator.php`
- Create: `app/Services/DemoData/DemoDataAuditor.php`
- Create: `database/seeders/PortalDemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/DemoData/PortalDemoSeederTest.php`

**Interfaces:**

- Consumes all `DemoDataStage` implementations in dependency order.
- Produces: `DemoDataOrchestrator::run(?Closure $progress = null): DemoAuditReport`.
- Produces: `DemoDataAuditor::audit(DemoDataOptions $options): DemoAuditReport` and `assertValid(): void`.

- [ ] **Step 1: Write failing end-to-end reduced-profile test**

Create eight published title fixtures with media, configure four demo users and reduced domain bounds, `Storage::fake('uploads')`, `Http::preventStrayRequests()`, and `Mail::fake()`. Run `PortalDemoSeeder` twice. Assert all stage reports, exact half coverage, zero audit violations, unchanged second-run counts, preserved pre-existing title/provider review, valid assets, no mail, and production guard before writes.

- [ ] **Step 2: Run focused end-to-end test**

```bash
php artisan test tests/Feature/DemoData/PortalDemoSeederTest.php
```

Expected: FAIL because orchestrator/auditor/seeder are missing.

- [ ] **Step 3: Implement orchestrator and guard order**

Before stage execution: call environment guard, verify required tables/columns, calculate published count/expected rows, call `disk_free_space(database_path())`, and require configured reserve plus an estimate based on pair count. Then run stages in the fixed dependency order and print stage/chunk progress through the closure or Seeder command.

- [ ] **Step 4: Implement final audit queries**

Use aggregate SQL, never full Eloquent hydration, to verify user count, half coverage, duplicate groups, exact text duplicates, self-interactions, enum coverage, timestamp inversions, missing asset paths, invalid foreign-key relations, and preservation snapshots. `assertValid()` throws one Russian exception listing bounded first violations and total counts.

- [ ] **Step 5: Integrate seeder entrypoints**

`PortalDemoSeeder::run()` resolves the orchestrator and reports progress. `DatabaseSeeder` continues calling `UserSeeder` and `AdminSeeder`, then calls `PortalDemoSeeder` only when `app()->environment('dev') && config('demo-data.enabled')`. Direct `php artisan db:seed --class=PortalDemoSeeder` works in `dev|testing`; production throws before a write.

- [ ] **Step 6: Run end-to-end tests, format, and commit**

```bash
php artisan test tests/Feature/DemoData/PortalDemoSeederTest.php tests/Feature/LocalAccountSeederTest.php
./vendor/bin/pint --dirty --format agent
git add app/Services/DemoData/DemoDataOrchestrator.php app/Services/DemoData/DemoDataAuditor.php database/seeders/PortalDemoSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/DemoData/PortalDemoSeederTest.php
git commit -m "feat: orchestrate full portal demo seed"
```

---

### Task 12: Documentation, full verification, and current dev database seed

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: thematic owner from `docs/README.md`
- Modify: `docs/superpowers/plans/2026-07-16-full-portal-demo-seed.md` only to check completed steps during execution.

**Interfaces:**

- Documents exact command, environment guard, credentials, expected volume, resume/idempotence behavior, disk reserve, and audit output.

- [ ] **Step 1: Update Russian documentation**

Add to `README.md`:

```markdown
### Полное демонстрационное наполнение

В среде `dev` команда `php artisan db:seed` создаёт 100 подтверждённых демонстрационных пользователей `user1@example.com`–`user100@example.com` с паролем `password` и заполняет пользовательские разделы текущего каталога. Повторный запуск идемпотентен; production-запуск запрещён. Набор может занимать десятки миллионов строк, поэтому перед записью автоматически проверяется свободное место.
```

Add the same operational contract to the documentation owner selected by `docs/README.md`, a technical entry to `CHANGELOG.md`, and a visitor-facing dated README history entry describing the now-populated demo portal without internal class names.

- [ ] **Step 2: Refresh managed docs only when required**

```bash
php artisan project:docs-refresh
```

Run only if the changed thematic source participates in managed blocks; never edit those blocks manually.

- [ ] **Step 3: Run focused and complete automated verification**

```bash
php artisan test tests/Unit/DemoData tests/Feature/DemoData tests/Feature/LocalAccountSeederTest.php
./vendor/bin/pint --dirty --format agent
php artisan test
```

Expected: all tests pass and Pint reports no remaining dirty formatting changes.

- [ ] **Step 4: Preview current dev capacity and run the full seed**

Run the seeder only after tests and the orchestrator's preflight report confirms the environment and disk reserve:

```bash
php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
```

Expected: all stages complete, the final audit reports zero violations, 100 demo users exist, each has exactly half-catalog state/review/comment coverage, every workflow enum is represented, and no duplicate count grows on an immediate second run.

- [ ] **Step 5: Repeat seed to prove idempotence**

```bash
php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
```

Expected: final domain counts remain unchanged; asset paths and stable IDs remain identical.

- [ ] **Step 6: Inspect final data and repository state**

```bash
git status --short --branch
git diff --check
php artisan about --only=environment
```

Review aggregate SQLite counts, exact duplicate audit, and a bounded sample of Russian profiles, reviews, comments, dialogues, requests, reports, and issues. Do not display password hashes, token hashes, session payloads, or private moderation notes in tool output.

- [ ] **Step 7: Commit only authorized implementation/documentation files**

Before committing, compare staged paths against this plan and the original dirty-tree inventory. Use exact-path commits and never include unrelated changes. If unrelated edits overlap `README.md`, `CHANGELOG.md`, or the thematic owner and cannot be separated safely, report that as the remaining commit/clean-tree blocker instead of overwriting or committing another user's work.

```bash
git commit -m "docs: document full portal demo seed"
```

Expected: implementation commits contain only demo-seed files/hunks; the existing shared unrelated changes remain preserved and explicitly reported if they prevent a clean final tree.
