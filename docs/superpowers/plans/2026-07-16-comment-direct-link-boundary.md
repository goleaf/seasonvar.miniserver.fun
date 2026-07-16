# Comment Direct-Link Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move canonical comment direct-link authorization and destination resolution out of `CommentRedirectController` without changing any public route, URL, response, or stored data.

**Architecture:** Add one transport-neutral `CommentDirectLinkResolver` beside the existing comment query and target resolver. The service owns comment lookup, policy/gate decisions, moderator fallback, structural-root/page resolution, and trusted internal URL assembly; the controller retains request normalization, the fail-closed schema gate, and private no-store/noindex redirect construction.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, Laravel gates/policies, Laravel routing, Pint 1.29, PHPStan/Larastan.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Preserve every existing comment, reply, reaction, report, moderation state, route, anchor, localized URL, and target visibility rule.
- Do not introduce migrations, dependencies, routes, cache keys, translations, public API changes, or a second comments architecture.
- Keep `CommentTargetResolver`, `CommentDiscussionQuery`, `CommentPolicy`, `CommentSchema`, and the `manage-comments` gate as canonical sources of truth.
- Do not create or run automated tests for Task 12; use exact-file static checks, route inspection, container probes, and manual acceptance inspection.
- Do not stage, overwrite, commit, or push unrelated shared-worktree changes.

---

### Task 1: Add the direct-link application service

**Files:**
- Create: `app/Services/Comments/CommentDirectLinkResolver.php`

**Interfaces:**
- Consumes: `CommentTargetResolver::fromComment(Comment, ?User, ?string): CommentTarget`, `CommentDiscussionQuery::rootFor(Comment): Comment`, `CommentDiscussionQuery::oldestPageFor(CommentTarget, Comment, ?User): int`, `CommentPolicy`, and the `manage-comments` gate.
- Produces: `CommentDirectLinkResolver::resolve(int $commentId, ?User $viewer, ?string $interfaceLocale = null): string`.

- [x] **Step 1: Create the focused resolver**

```php
<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

final readonly class CommentDirectLinkResolver
{
    public function __construct(
        private CommentTargetResolver $targets,
        private CommentDiscussionQuery $discussion,
    ) {}

    public function resolve(int $commentId, ?User $viewer, ?string $interfaceLocale = null): string
    {
        if ($commentId < 1) {
            throw $this->notFound($commentId);
        }

        $comment = Comment::query()->withTrashed()->findOrFail($commentId);

        if (! Gate::forUser($viewer)->allows('view', $comment)) {
            throw $this->notFound($commentId);
        }

        $isModerator = $viewer !== null && Gate::forUser($viewer)->allows('manage-comments');

        if ($isModerator
            && ($comment->status !== CommentStatus::Published || $comment->deleted_at !== null)) {
            return $this->moderationUrl($comment);
        }

        try {
            $target = $this->targets->fromComment($comment, $viewer, $interfaceLocale);
        } catch (ModelNotFoundException $exception) {
            if (! $isModerator) {
                throw $exception;
            }

            return $this->moderationUrl($comment);
        }

        $root = $this->discussion->rootFor($comment);
        $page = $this->discussion->oldestPageFor($target, $root, $viewer);
        $canonical = explode('#', $target->canonicalUrl, 2)[0];
        $separator = str_contains($canonical, '?') ? '&' : '?';
        $query = http_build_query(array_filter([
            'discussion_scope' => $target->type->value.':'.$target->id,
            'discussion_sort' => 'oldest',
            'comments_page' => $page > 1 ? $page : null,
            'thread' => $comment->parent_id !== null ? (int) $root->id : null,
            'comment' => (int) $comment->id,
        ], static fn (mixed $value): bool => $value !== null));

        return $canonical.$separator.$query.'#comment-'.$comment->id;
    }

    private function moderationUrl(Comment $comment): string
    {
        return route('admin.comments', ['comment' => $comment->id]);
    }

    private function notFound(int $commentId): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(Comment::class, [$commentId]);
    }
}
```

- [x] **Step 2: Run exact-file syntax and formatting checks**

Run:

```bash
php -l app/Services/Comments/CommentDirectLinkResolver.php
./vendor/bin/pint app/Services/Comments/CommentDirectLinkResolver.php --format agent
php -l app/Services/Comments/CommentDirectLinkResolver.php
```

Expected: both syntax checks report no errors and Pint exits `0` without touching unrelated files.

- [x] **Step 3: Commit only the resolver**

Create an atomic main-branch commit containing only `app/Services/Comments/CommentDirectLinkResolver.php` with message:

```text
refactor: extract comment direct-link resolver
```

Before publishing, compare `main` and `origin/main`; never push unrelated interleaved commits that are not already present remotely.

---

### Task 2: Reduce the controller to an HTTP adapter

**Files:**
- Modify: `app/Http/Controllers/CommentRedirectController.php`

**Interfaces:**
- Consumes: `CommentDirectLinkResolver::resolve(int, ?User, ?string): string` and `CommentSchema::writable(): bool`.
- Produces: the unchanged Laravel `RedirectResponse` with the existing URL contract and private no-store/noindex headers.

- [x] **Step 1: Replace application orchestration with resolver delegation**

Keep only these imports:

```php
use App\Models\User;
use App\Services\Comments\CommentDirectLinkResolver;
use App\Services\Comments\CommentSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
```

Replace `__invoke()` and remove `moderationRedirect()` so the class body is:

```php
final class CommentRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        string $comment,
        CommentSchema $schema,
        CommentDirectLinkResolver $links,
    ): RedirectResponse {
        abort_unless($schema->writable(), 404);
        abort_unless(ctype_digit($comment) && (int) $comment > 0, 404);
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $locale = $request->route('locale');
        $locale = is_string($locale) ? $locale : null;

        return redirect()->to($links->resolve((int) $comment, $viewer, $locale))
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
```

- [x] **Step 2: Run exact-file syntax and formatting checks**

Run:

```bash
php -l app/Http/Controllers/CommentRedirectController.php
./vendor/bin/pint app/Http/Controllers/CommentRedirectController.php --format agent
php -l app/Http/Controllers/CommentRedirectController.php
```

Expected: both syntax checks report no errors and Pint exits `0` without touching unrelated files.

- [x] **Step 3: Commit only the controller**

Create an atomic main-branch commit containing only `app/Http/Controllers/CommentRedirectController.php` with message:

```text
refactor: thin comment redirect controller
```

---

### Task 3: Verify compatibility and document the completed boundary

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: the completed resolver/controller boundary and the existing source route registry.
- Produces: project documentation identifying the canonical service owner and a focused verification record.

- [x] **Step 1: Inspect exact routes without using an optimized route cache**

Run:

```bash
APP_ENV=testing php artisan route:list --path=comments --except-vendor
APP_ENV=testing php artisan route:list --path=admin/comments --except-vendor
```

Expected: `comments.show`, `localized.comments.show`, and `admin.comments` retain their methods, URIs, names, and middleware.

- [x] **Step 2: Run scoped static analysis**

Run:

```bash
php -d memory_limit=1G ./vendor/bin/phpstan analyse \
  app/Services/Comments/CommentDirectLinkResolver.php \
  app/Http/Controllers/CommentRedirectController.php \
  app/Services/Comments/CommentTargetResolver.php \
  app/Services/Comments/CommentDiscussionQuery.php \
  --no-progress
```

Expected: PHPStan exits `0` with no diagnostics.

- [x] **Step 3: Run a read-only container/schema probe**

Run a booted Laravel probe that resolves `CommentDirectLinkResolver`, reports `CommentSchema::available()`/`writable()`, and reports the number of existing comments without inserting, updating, or deleting any row.

Expected: the resolver is container-resolvable; schema capability and fixture count are reported without exception or database mutation. If eligible fixture rows exist, additionally resolve one public root/reply and one moderator-only destination using existing users only; otherwise record that URL behavior was verified by static equivalence inspection.

- [x] **Step 4: Perform the security and compatibility inspection**

Confirm all of the following directly from the changed and related files:

- the client controls only a positive decimal comment ID and optional route locale;
- target type, target ID, page, thread, route name, URL, moderator state, and viewer ID are server-owned;
- `CommentPolicy`, `manage-comments`, the target allowlist, target visibility, and structural-root checks still run;
- ordinary denied/inaccessible rows remain `404` and moderator fallback remains private;
- public URL query names/order semantics and `#comment-{id}` remain unchanged;
- both redirects retain `private, no-store` and `noindex, nofollow`;
- no migrations, routes, translations, API/OpenAPI fields, caches, or stored records changed.

- [x] **Step 5: Update canonical documentation**

In `docs/architecture.md`, extend the existing direct-comment paragraph to name `CommentDirectLinkResolver` as the owner of lookup, authorization, moderator fallback, root/page resolution, and trusted URL assembly, while the controller owns request/response headers.

In `docs/plans/laravel-video-portal-modernization.md`, append a checked Phase 5.3 item recording the completed controller boundary and verification scope.

In the English `CHANGELOG.md`, add one concise unreleased entry stating that direct-comment resolution moved behind the canonical comments service without route, URL, policy, or data changes.

- [x] **Step 6: Review the final diff and commit only scoped documentation**

Run:

```bash
git diff --check -- \
  app/Services/Comments/CommentDirectLinkResolver.php \
  app/Http/Controllers/CommentRedirectController.php \
  docs/architecture.md \
  docs/plans/laravel-video-portal-modernization.md \
  CHANGELOG.md
```

Inspect the exact scoped diff and create a documentation commit with message:

```text
docs: record comment direct-link boundary
```

Publish every scoped commit to the existing remote `main` only after verifying it is a fast-forward over the current remote main. Report any unrelated dirty paths as preserved shared-worktree state rather than committing them.
