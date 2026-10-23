---
paths:
    - 'app/Actions/Order/**,app/Services/Order/**,app/Http/Controllers/Administration/Order*,resources/js/pages/Administration/Orders/**'
---

# Js Pages Administration Orders

## Require the current handler for administrator order processing

All administrator processing entry points must pass the authenticated actor to OrderProcessingService. Recheck orders.process and assigned_handler_id after locking/reloading Order; Manager/Owner must explicitly reassign before processing. Apply this even when handler_required is false. Actor-less calls are trusted internal workflows only; preserve customer cancellation separately. Keep Tasks shared for supervisors, final-handler sale attribution, and the existing final-handler-only delivered tracking-reference correction after completion.
