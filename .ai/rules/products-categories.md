---
paths:
  - 'app/Models/{Product,Category}.php,database/migrations/**,app/Actions/{Product,Category}/**,resources/js/pages/Administration/{Products,Categories}/**'
---

# Products Categories

## Recoverable catalog deletion
The explicit owner-approved recovery flow supersedes the earlier no-soft-deletes decision for products and categories only. Use soft deletion, retain inventory, media, tags, and historical references, record deleted_by_id, and restore records inactive. Deleted-list and restore endpoints require catalog.delete authorization; restore products only while their category is live.
