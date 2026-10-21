---
paths:
    - 'tests/**'
---

# Tests

## Isolate fake storage between independent test runs

Storage::fake clears its root, so independent ordinary Pest/Artisan runs previously erased each other's payment proofs and coverage manifests. Tests\TestCase supplies a process-ID token only when Laravel has no native test token; preserve native parallel-worker tokens and initialize this on each application creation. Keep filesystem assertions strict rather than accepting missing, extra or corrupt fixtures.
