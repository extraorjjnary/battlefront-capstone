---
paths:
    - 'app/Http/{Requests,Controllers}/Administration/SalesReport*,resources/js/pages/Administration/Reports/**'
---

# Administration Reports

## Remember applied sales selections per administrator session

Persist only normalized from/to/period/product_id/category_id under the authenticated administrator's session key. Any explicit report-filter URL replaces the whole saved selection; omitted IDs clear filters. Bare and page-only URLs restore preferences and regenerate reports. Reject invalid URLs before saving; stale saved selections fall back to defaults. Exports resolve the same selection without saving; POST reset forgets only the current administrator's selection.
