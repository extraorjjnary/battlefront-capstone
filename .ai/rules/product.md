---
paths:
  - 'app/Services/RealCatalogImportService.php,app/Services/CatalogShippingClassification.php,app/Actions/Product/**'
---

# Product

## Preserve reviewed catalog shipping assignments
Use the versioned exact code/name/category mapping only for eligible imported Standard products without a shipping_profile_source. Uncertain classifications require administrator review; do not infer protective handling from broad categories alone. Administrator edits or explicit confirmation mark the source manual, including Standard. Reimports preserve existing profile/source and never reset manual choices.
