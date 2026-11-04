---
paths:
  - 'app/Http/Controllers/Administration/Order*,app/Http/Requests/Administration/*IndexRequest.php,app/Repositories/Order/**,app/Services/Reporting/ShipmentExportService.php,resources/js/pages/Administration/Orders/**,resources/views/reports/shipments.blade.php'
---

# Views Reports

## Keep shipment directory and documents read-only and filter-aligned
Orders?view=shipments lists existing delivery shipments with independent filters and shared repository/presenter queries; preserve default Orders queue/history and all handoff editing gates. Require orders.view; CSV/PDF/print also require reports.export. Export all filtered matches in directory order, ignoring pagination. Stream CSV with safe text identifiers/formula protection; cap PDF/print at 500 with an explicit error, never truncation. Use Philippine handoff date boundaries, preserve missing historical fields and confirmed zero costs, and never create/backfill/reprice records or include shipping charges in product sales.
