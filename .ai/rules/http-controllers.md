---
paths:
  - 'app/Http/Controllers/**'
---

# Http Controllers

## Use the controller helper for policy authorization
In controllers, invoke model policy abilities with `$this->authorize(...)` so policy checks are clear at the call site. Keep named administrator-area gates on route middleware; reserve the Gate facade for cases the controller helper cannot express.
