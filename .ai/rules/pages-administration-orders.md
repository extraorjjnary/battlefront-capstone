---
paths:
  - 'app/Http/Controllers/Administration/OrderController.php,resources/js/pages/Administration/Orders/Index.vue'
---

# Pages Administration Orders

## Keep the administrator order directory operational
Default the administrator order directory to the actionable Pending + Processing queue. Keep Completed and Cancelled orders in separate historical views; filtering and pagination must not delete, archive, or change persisted order statuses.
