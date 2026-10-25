---
paths:
    - 'app/Actions/Inventory/**,app/Http/{Controllers,Requests}/Administration/*Inventory*,resources/js/pages/Administration/Inventory.vue,resources/js/pages/Administration/Reports/StockReconciliation.vue'
---

# Pages Administration Reports

## Apply Owner and Manager stock corrections directly while preserving Staff approval

Owner/Manager direct stock changes require both inventory.request and inventory.approve through the derived inventory.adjust Gate. Staff use RequestInventoryAdjustment and independent DecideInventoryAdjustment; IT cannot adjust or approve. Direct changes lock product/inventory, require a quantity snapshot and explicit HTTP confirmation, require notes for theft/loss, and atomically append linked audit/movement records without creating requests or approval notifications. Preserve pending/historical requests, stale approval checks, and the separate inventory.reorder workflow.
