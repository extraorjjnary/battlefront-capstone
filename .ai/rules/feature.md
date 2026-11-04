---
paths:
  - 'app/Providers/AppServiceProvider.php,app/Jobs/**,config/queue.php,tests/Feature/PayMongoRecoveryConcurrencyTest.php'
---

# Feature

## Validate payment timeout recovery on isolated Linux workers
Developer approval 2026-11-05 permits generated test-database creation and deletion only in disposable CI for this integration. Keep payments separate from default indexing and notification recovery, with shared atomic locks. The payments listener allows 70 seconds around 60-second reconciliation jobs, below the 90-second reservation; order locks and uniqueness use 120 seconds. Windows synchronous tests do not establish PCNTL termination safety. Real databases and provider operations remain separately controlled.
