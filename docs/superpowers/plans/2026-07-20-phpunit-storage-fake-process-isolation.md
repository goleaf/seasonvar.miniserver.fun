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
- [x] Commit the implementation on the existing `main` as `096c66f573df6ae914e2aa0061d928c3ee9c2909`; deliver it together with this final evidence through the configured pre-push gate and verify local/origin HEAD equality.

### Verification evidence

- RED contract failed on the missing process token before the base-test guard; ordinary and `TEST_TOKEN=runner-7` GREEN runs then passed with 1 test and 2 assertions each.
- Two independent `DemoCatalogCorpusStageTest` processes passed concurrently with 4 tests and 5,575 assertions each; the full `Storage::fake()` inventory passed 56 tests and 7,937 assertions.
- The final combined cache/storage/CI focused run passed 80 tests and 5,991 assertions with 9 expected infrastructure skips.
- The full repository suite passed 1,427 tests: 1,416 passed, 11 expected skips, 122,945 assertions.
