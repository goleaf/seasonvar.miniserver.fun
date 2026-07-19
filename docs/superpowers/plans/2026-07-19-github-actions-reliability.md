# GitHub Actions Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent the reproduced managed-documentation CI failure before commit and make the existing GitHub Actions environment reproducible without weakening any quality gate.

**Architecture:** `scripts/ci-check.sh` remains the single executable quality-gate owner and gains an isolated read-only `docs` profile shared by backend and pre-commit. The workflow keeps existing PHP/Node/action major contracts while pinning the runner image and every action to an immutable reviewed commit SHA.

**Tech Stack:** Bash, Git hooks, GitHub Actions, PHP 8.5, Laravel 13.20, PHPUnit 12.5, Node 26.

## Global Constraints

- Work only on the existing `main`; never create a branch/worktree or absorb unrelated dirty-tree changes.
- Do not disable audits/tests, add `continue-on-error`, hide failures, auto-stage hook output, edit `.env`, clear application stores, or change dependency/runtime versions.
- Keep `scripts/ci-check.sh` as the only owner of executable CI steps.
- Use only exact action SHAs verified against the already accepted major tags.
- Preserve Russian README/CHANGELOG policy and generated `project-docs` ownership.

---

### Task 1: Record evidence, design, and compliance before code

**Files:**

- Create: `docs/superpowers/specs/2026-07-19-github-actions-reliability-design.md`
- Create: `docs/superpowers/plans/2026-07-19-github-actions-reliability.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Record public run/job/SHA evidence and clean-snapshot reproduction.
- [x] Record cross-feature impact, migration/rollback/data-safety/production review and `completed|already_compliant|not_applicable|unresolved` matrix.
- [x] Keep absolute future availability and external provider health explicitly unresolved.

### Task 2: Add failing CI regression contracts

**Files:**

- Modify: `tests/Unit/CiQualityGateContractTest.php`

- [x] Replace floating-action assertions with exact SHA plus readable major comments.
- [x] Assert all three jobs use `ubuntu-24.04` and none uses `ubuntu-latest`.
- [x] Assert `run_docs` owns isolated in-memory SQLite setup, backend calls it, and a public `docs` profile exists.
- [x] Assert pre-commit runs the shared docs profile after clean-tree guards and before README/CHANGELOG policies.
- [x] Reproduce and contract-test the generated `bootstrap/cache` manifest race without excluding source PHP files.
- [x] Run `php artisan test --filter=CiQualityGateContractTest` in an isolated HEAD snapshot and confirm failures on the missing contracts.

### Task 3: Implement the prevention gate

**Files:**

- Modify: `scripts/ci-check.sh`
- Modify: `.githooks/pre-commit`

- [x] Add `run_docs()` with `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and `php artisan project:docs-refresh --check --no-interaction`.
- [x] Make backend call `run_docs` and expose the `docs` case/profile help text.
- [x] Invoke `bash "$repo_root/scripts/ci-check.sh" docs` in pre-commit after all cleanliness guards.
- [x] Run the focused contract and confirm green.
- [x] Prove stale docs fail, run the canonical refresher, and prove the same docs profile passes.
- [x] Isolate every profile from a shared file-based maintenance marker through process-local `cache`/`array` settings; preserve the real marker untouched.

### Task 4: Pin the workflow environment

**Files:**

- Modify: `.github/workflows/ci.yml`

- [x] Change each `runs-on` to `ubuntu-24.04`.
- [x] Pin `actions/checkout` to `df4cb1c069e1874edd31b4311f1884172cec0e10` (`v6`).
- [x] Pin `actions/cache` to `caa296126883cff596d87d8935842f9db880ef25` (`v5`).
- [x] Pin `actions/setup-node` to `249970729cb0ef3589644e2896645e5dc5ba9c38` (`v6`).
- [x] Pin `actions/upload-artifact` to `043fb46d1a93c77aae656e7c1c64a875d1fc6a0a` (`v7`).
- [x] Pin `shivammathur/setup-php` to `f3e473d116dcccaddc5834248c87452386958240` (`v2`).
- [x] Set `persist-credentials: false` on every read-only checkout.
- [x] Re-run focused contract and validate workflow/static legacy scan.

### Task 5: Update canonical operational documentation

**Files:**

- Modify: `docs/ci.md`
- Modify: `docs/development.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [x] Document pre-commit docs freshness, `docs` profile, exact runner/actions policy, refresh recovery and external outage limitation.
- [x] Add a dated maintenance decision retaining current action majors while pinning their commits; no dependency/package upgrade.
- [x] Add Russian visitor/development history without claiming guaranteed third-party uptime.
- [x] Run `php artisan project:docs-refresh`, README policy, CHANGELOG policy and Markdown link check.

### Task 6: Verify, audit leftovers, and deliver

- [x] Run `bash -n scripts/ci-check.sh .githooks/pre-commit .githooks/pre-push`.
- [x] Run `php artisan test --filter=CiQualityGateContractTest`.
- [x] Run `./vendor/bin/pint --dirty --format agent` if PHP changed.
- [x] Run `bash scripts/ci-check.sh backend`, then `frontend`, then `browser` on the stable task snapshot; record `1 419` backend tests / `122 920` assertions, frontend audit 0 / Vite 23 modules, and `41` browser passes / `4` expected skips.
- [x] Search the repository for `ubuntu-latest`, floating `uses: ...@v`, duplicate docs commands, stale managed docs and unfinished task code; review dependencies before removal.
- [x] Re-read applicable requirements/design/current plan and finalize every compliance row honestly.
- [x] Inspect remote runs `#213` and `#214`; reproduce the two backend failures from their authenticated job logs without weakening the public readiness or filesystem-permission contracts.
- [x] Add RED/GREEN regressions for the minimal readiness payload, fake-upload Unix group isolation, and explicit `gd` provisioning in both PHP jobs.
- [ ] Confirm `git status --short --branch` is clean on `main`, commit the coherent task, and push `origin main`.
- [ ] Monitor a new GitHub Actions run containing the remote-only fixes through completion; if authentication or an external service blocks delivery, preserve it as `unresolved` with exact evidence.
