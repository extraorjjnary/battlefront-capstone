---
paths:
    - 'database/seeders/DevelopmentHistoricalSalesSeeder.php,app/Console/Commands/ResetDevCommand.php,database/seeders/DevelopmentCustomerSeeder.php'
---

# Commands Seeders

## Distribute V4 history across the immutable demo customer roster without cleanup

The developer-approved V4 refinement supersedes prior rules about dedicated-account ownership, recipient labels, expired-history pruning and a commerce-free demo roster. DevelopmentHistoricalSalesSeeder calls DevelopmentCustomerSeeder transactionally and distributes calendar slots across all 30 existing demo identities; recipient_name is the assigned name and the nullable unique private development_fixture_key identifies history. Preserve matching accounts, ordinary transactions, catalog/inventory, all old fixtures and immutable snapshots; Sale.amount is product subtotal only. Reject V1-V3/quarterly/DEVHIST40 history before changing accounts, records or coverage. Reject damaged V4 history without repairs, invalidate coverage on preparation failure, and preserve publication retry semantics. Coverage remains the current 48 months, while expired records are retained. Never perform historical cleanup, reassignment or deletion without separate developer approval and development-only ownership validation.
