---
paths:
    - 'app/Console/Commands/*Demonstration*.php,app/Services/{Demonstration*,HostedDemoHistory}.php,config/demonstration.php,app/Repositories/Reporting/{MonthlySalesRepository,SalesHistoryCoverageRepository}.php,app/Services/Reporting/ForecastingService.php'
---

# Services Reporting

## Provision hosted defense fixtures without changing development seeders

Use battlefront:provision-demo and battlefront:disable-demo-accounts with --confirm-demo; both are dry-run unless --apply. Require catalog-demo disclosure, exact configured connection/host/database and a separate temporary provisioning gate. Preserve the 654 imported products, images, existing stock and commerce; original development seeders/reset safeguards stay unchanged. Only the explicit 34 demo identities may be created active and administratively preverified, using one strong privately supplied password; audit preapproval as not OTP, reject unowned/conflicting identities, and never overwrite credentials or reactivate accounts. Retirement targets audited IDs, revokes credentials, retains history and requires a verified active non-demo Owner. Hosted synthetic coverage is durable audit metadata with transaction fingerprints and an independent runtime gate; aggregate only owned fixtures for its products, retain provenance and reject unowned forecast collisions. Keep all PC Builder specs draft and templates unpublished pending normal review.
