---
paths:
  - 'app/Models/{Category,Product}.php,app/Actions/Product/QueueCatalogSearchSync.php,app/Jobs/SyncCatalogSearch.php'
---

# Product Jobs

## Contain catalog enqueue failures after commit with operator recovery
Developer approved containment and operator recovery without an indexing outbox. Guard dispatch inside the after-commit callback; queue and diagnostic failures must not invalidate committed catalog saves. Keep current-state ID jobs and retries. Use SQL search during indexing incidents until full reconciliation and provider freshness are verified under controlled activation. Successful empty provider results remain empty; retain the documented crash gap.
