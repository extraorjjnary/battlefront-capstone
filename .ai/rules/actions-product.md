---
paths:
    - 'app/Actions/Product/**'
---

# Actions Product

## Preserve provenance when saving unchanged shipping handling

The approved handling UX refinement supersedes explicit review confirmation: remove the review checkbox/flag from administrator forms. Changing shipping_profile marks shipping_profile_source manual; saving the same profile preserves the existing source, including null or imported classifications, even if a retired shipping_profile_reviewed flag is supplied. New administrator profile assignments remain manual; reimports preserve profile/source.

## Block product deletion while stock or operations remain

Recoverable product deletion is allowed only after sellable and quarantined stock are zero and there are no pending/processing orders, pending inventory adjustments or damage reports, unresolved customer/supplier returns, or unpaid approved refunds. Deactivation remains available at any time; restoration returns the product inactive.
