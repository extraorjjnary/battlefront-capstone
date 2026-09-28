---
paths:
  - 'routes/api.php,app/Http/Controllers/Api/**,config/sanctum.php'
---

# Api

## Use bearer-only Sanctum for customer mobile auth
Keep Fortify on the web session guard. Mobile /api/v1 auth uses Sanctum personal access tokens with a 30-day per-token expiry and no session-guard fallback (sanctum.guard = []). Require auth:sanctum plus the customer gate for protected mobile routes; sign-out deletes only the presented token. Never expose token values outside registration/login responses.
