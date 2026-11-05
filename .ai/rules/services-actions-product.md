---
paths:
    - 'app/Providers/AppServiceProvider.php,app/Services/CatalogImagePipeline.php,app/Actions/Product/StoreProductImage.php'
---

# Services Actions Product

## Preserve Cloud bucket boundaries and local catalog preparation

When filesystems.default is private, alias legacy local protected-file consumers to the injected private disk during provider registration, after Laravel Cloud configures named disks. Do not route protected files through public. Herd retains existing local disks. Product uploads inherit disk/bucket visibility; explicit public object ACLs fail on Cloud R2. Catalog audits validate public object bytes, but image ingestion requires a local public disk for its exclusive-write safeguards; transfer verified images separately and retain private manifests/CSVs and production development_demo restrictions.
