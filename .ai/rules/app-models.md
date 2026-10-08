---
paths:
    - 'app/Models/**'
---

# App Models

## Apply the completed EXT-6 schema decisions

Use the manuscript ERD plus EXT-6's approved implementation decisions: Laravel auth infrastructure, unsigned big integer identifiers, forecasts.method, and is_active=true for categories, products, and chatbot knowledge. Do not use lifecycle enums or soft deletes; filter inactive records from customer/chatbot flows, retain history, and allow reactivation. Explicit compatibility checking and compatibility persistence are out of scope. Payment methods are deferred to EXT-80 (blocks EXT-32); order snapshot fields are deferred to EXT-81 (blocks EXT-31).

## EXT-80 and EXT-81 decisions are resolved

The earlier EXT-6 deferral is superseded by the approved order rule in models-migrations.md. Use the approved manual payment methods and order snapshot fields for Order persistence; do not treat EXT-80 or EXT-81 as unresolved.
