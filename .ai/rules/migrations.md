---
paths:
    - 'database/migrations/**'
---

# Migrations

## Apply the completed EXT-6 schema decisions

Use the manuscript ERD plus EXT-6's approved implementation decisions: Laravel auth infrastructure, unsigned big integer identifiers, forecasts.method, and is_active=true for categories, products, and chatbot knowledge. Do not use lifecycle enums or soft deletes; filter inactive records from customer/chatbot flows, retain history, and allow reactivation. Explicit compatibility checking and compatibility persistence are out of scope. Payment methods are deferred to EXT-80 (blocks EXT-32); order snapshot fields are deferred to EXT-81 (blocks EXT-31).

## Prefer model-aware migration foreign keys

Migration foreign-key convention: Prefer `foreignIdFor(Model::class)` for conventional Eloquent model foreign keys when it preserves the approved schema. Use explicit `foreignId()` when the column/table naming is non-standard or when explicit schema definition is clearer. Never let this convention override approved nullability, uniqueness, or delete/update behavior.

## EXT-80 and EXT-81 decisions are resolved

The earlier EXT-6 deferral is superseded by the approved order rule in models-migrations.md. Use the approved manual payment methods and order snapshot fields for Order persistence; do not treat EXT-80 or EXT-81 as unresolved.

## Limit retired chatbot preset deletion to the approved cleanup
The approved chatbot preset cleanup is a narrow exception to legacy knowledge retention: delete only inactive, unchanged resolver-owned development presets identified by frozen complete fingerprints in a new forward-only migration. Preserve active and edited/custom knowledge and current FAQs; do not edit the prior FAQ refinement migration or recreate deleted IDs on rollback.

## Preserve SQLite constraint triggers during column changes
Laravel SQLite column changes rebuild the table and discard custom triggers. Preserve or reinstall the affected application's constraint triggers after change(), in both upgrade and rollback paths, and verify constraint rejection after the rebuild. Delivery assumption labels retain complete synthetic and quote notices as nullable text; rollback must refuse labels exceeding the original 255-character limit.
