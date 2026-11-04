---
paths:
  - 'app/Repositories/Catalog/**,app/Models/{Category,Product}.php,app/Jobs/SyncCatalogSearch.php'
---

# Jobs

## Keep Algolia catalog vocabulary and metadata synchronized
Product search documents may include the public category label, and category renames must enqueue current product IDs after commit so those labels stay current. Keep curated equivalence synonyms in CatalogSearchIntent and synchronize them from config during catalog reconciliation without replacing unrelated remote synonyms. Do not equate distinct storage standards such as M.2, NVMe, SSD, and HDD.
