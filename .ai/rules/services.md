---
paths:
    - 'app/Console/Commands/**,app/Services/RealCatalogImportService.php,database/PrepareCatalogImageManifest.ps1'
    - 'app/Console/Commands/**,app/Services/{RealCatalogImportService,CloudCatalogSources,DemoCatalogInventory}.php,config/catalog-demo.php'
    - 'app/Console/Commands/*Demonstration*.php,app/Services/Demonstration*.php,docs/DISPOSABLE_DEMO_RESTORATION.md'
---

# Services

## Keep prepared demo inventory separate from spreadsheet provenance

catalog:prepare-demo-inventory updates only private audited sources in local/testing. Its 650/3/1 profile applies to the 654 imported products, excluding forecasting fixtures. Preserve source_quantity and workbook/image metadata; development_demo quantities are not verified client stock and cannot import outside local/testing. Populated manifest quantities prohibit CSV overrides; ordinary reimports preserve operational quantities.

## Separate hosted capstone stock provisioning from production catalog imports

Developer-approved Cloud migration uses catalog:import-cloud for catalog fields only and catalog:provision-demo-inventory for the private fingerprint-pinned retailer-demo-v1 profile. Keep ordinary development_demo inventory imports and source preparation local/testing-only. Hosted demo provisioning requires permanent demo disclosure, a separate temporary provisioning gate, an exact configured database match and explicit apply/confirmation; initialize missing stock atomically with audit/movement history, never refill or overwrite existing stock. Stage private inputs per invocation and preserve original bytes.

## Restore demo baselines only through isolated operator resources

Use the operator runbook and read-only battlefront:demo-baseline capture/verify; never clean protected history or expose a reset route. Baselines exclude gateway/auth/queue state and require original audited stock plus hosted fixture ownership. Stop old processes and reconcile/close provider flows before switching to a validated replacement database and matching buckets; delayed numeric-ID jobs must never cross cycles. Cloud isolation and restore rehearsal remain operator gates, not manifest guarantees.
