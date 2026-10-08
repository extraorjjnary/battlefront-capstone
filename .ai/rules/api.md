---
paths:
    - 'routes/api.php,app/Http/Controllers/Api/**,config/sanctum.php'
---

# Api

## Use bearer-only Sanctum for customer mobile auth

Keep Fortify on the web session guard. Mobile /api/v1 auth uses Sanctum personal access tokens with a 30-day per-token expiry and no session-guard fallback (sanctum.guard = []). Require auth:sanctum plus the customer gate for protected mobile routes; sign-out deletes only the presented token. Never expose token values outside registration/login responses.

## Allow optional bearer authentication for mobile chatbot

Mobile chatbot allows guests and Sanctum customers, denies administrators, and returns 401 for invalid supplied Authorization credentials. Resolve the bearer identity before the shared chatbot limiter (5/min guest IP, 10/min customer); web sessions do not authenticate API callers. Guest context tokens continue public topics only and are not identity credentials; customer context remains token/user-bound. Guest order questions use the shared sign-in fallback.
