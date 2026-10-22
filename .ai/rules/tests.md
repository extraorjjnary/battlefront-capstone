---
paths:
    - 'tests/**'
---

# Tests

## Isolate fake storage between independent test runs

Storage::fake clears its root, so independent ordinary Pest/Artisan runs previously erased each other's payment proofs and coverage manifests. Tests\TestCase supplies a process-ID token only when Laravel has no native test token; preserve native parallel-worker tokens and initialize this on each application creation. Keep filesystem assertions strict rather than accepting missing, extra or corrupt fixtures.

## Isolate fake disk names when parallel runner tokens can repeat

Laravel worker tokens can repeat across independent test runners. Use Tests\TestCase::fakeIsolatedStorage for catalog-import/reset fixtures that must survive concurrent runs: it namespaces the fake disk name by process ID, preserves the native worker token and original disk configuration, and registers the fake under the normal disk alias. Do not change production storage paths or weaken missing/corrupt-file assertions.

## Give each isolated fake a fresh directory

fakeIsolatedStorage must use a fresh directory on every invocation, even within the same worker process. A process/worker-only namespace still deletes and reuses earlier fixture paths, exposing Windows image writes to transient file locks. Preserve the normal disk alias, configuration and native worker token; do not retry or suppress failed writes.
