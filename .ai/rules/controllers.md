---
paths:
  - 'routes/**,app/Http/Controllers/**'
---

# Controllers

## Enforce administrator access through the Gate
Every administrator route must run auth before can:access-administration (or use ->can('access-administration')). Controller actions that are not protected at the route boundary must call Gate::authorize('access-administration'). Defining the Gate alone does not protect an endpoint.
