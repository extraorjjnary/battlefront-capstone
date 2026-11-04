---
paths:
    - app/Console/Commands/ResetDevCommand.php
---

# App Console Commands

## Keep supplier demonstration setup opt-in after catalog import

battlefront:reset-dev seeds DevelopmentSupplierSeeder only with --with-suppliers, after successful catalog import and before builder drafts/history. Validate its required catalog codes during preflight before database recreation; stop on supplier seeding failure. Keep DatabaseSeeder unchanged and never execute a real reset merely to verify this option.
