# PHPUnit Storage Fake Process Isolation Plan

**Goal:** Prevent independent serial PHPUnit processes in one checkout from cleaning each other's `Storage::fake()` files.

**Architecture:** Reuse Laravel's existing `ParallelTesting::token()` suffix. Supply PID only when no runner token exists; preserve Paratest and production storage behavior.

**Tech Stack:** PHP 8.5, Laravel 13.20, PHPUnit 12.5.

### Task 1: Prove the collision boundary

- [x] Trace `Storage::fake()` to its shared root and conditional token suffix in installed Laravel source.
- [x] Record full-suite failure versus four isolated green runs and neighboring-stage green run.
- [x] Add a contract requiring a serial PID token and suffixed fake root.
- [x] Run RED before modifying `Tests\TestCase`.

### Task 2: Add the smallest compatible guard

- [x] Set a PID resolver only when `ParallelTesting::token()` is absent.
- [x] Preserve an existing runner token and all disk aliases/call sites.
- [x] Run focused GREEN, fake-storage users and repeated concurrent-process reproduction.

### Task 3: Verify and deliver

- [x] Run Pint, related tests, full suite, managed docs, diff and legacy scans.
- [x] Update current compliance, CHANGELOG and README assessment.
- [ ] Commit/push only when the shared Git guard permits a clean scoped delivery.
