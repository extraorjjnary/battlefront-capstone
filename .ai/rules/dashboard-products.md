---
paths:
    - 'app/Http/Controllers/{DashboardController,ProductCatalogController}.php,resources/js/pages/{Dashboard,Products}/**'
---

# Dashboard Products

## Preserve deferred loading and catalog request boundaries

Dashboard data and enabled dashboard/catalog recommendation feeds load through named Inertia deferred groups. Render these regions through DeferredContent with local skeleton and rescue/retry slots; feature tests must load the appropriate group before checking its data. Keep catalog products in the initial response and pagination inside a lazy Inertia::scroll callback so recommendation-only requests skip pagination. Preserve the global progress bar and existing cards during infinite scrolling. Include recommendations_enabled in catalog query reloads, and suppress recommendation placeholders for paused personalization or explicit search/filter intent.
