---
paths:
  - 'routes/api.php,app/Http/Controllers/Api/**,app/Http/Resources/**'
---

# Resources

## Keep the mobile API on Laravel's JSON contract
Place mobile routes under the stateless /api/v1 group and keep Inertia web routes separate. Return JsonResource data envelopes and Laravel's native paginated data/links/meta; retain Laravel's message and validation errors JSON shapes. The v1 group uses the api-v1 60-per-minute per-IP limiter. Add endpoint-specific limits only when needed.
