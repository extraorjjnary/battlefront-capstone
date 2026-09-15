---
paths:
  - 'app/Models/Order.php,app/Http/Controllers/OrderController.php,resources/js/pages/Orders/**'
---

# Pages Orders

## Format one global public order reference
Keep orders.id as the global primary key and route key. Expose its customer-facing reference through Order::reference in BF-000001 format; do not create per-customer sequences or duplicate formatting in controllers or Vue.
