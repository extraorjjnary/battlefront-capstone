---
paths:
  - 'app/Models/**'
---

# App Models

## Apply the completed EXT-6 schema decisions
Use the manuscript ERD plus EXT-6's approved implementation decisions: Laravel auth infrastructure, unsigned big integer identifiers, forecasts.method, and is_active=true for categories, products, and chatbot knowledge. Do not use lifecycle enums or soft deletes; filter inactive records from customer/chatbot flows, retain history, and allow reactivation. Explicit compatibility checking and compatibility persistence are out of scope. Payment methods are deferred to EXT-80 (blocks EXT-32); order snapshot fields are deferred to EXT-81 (blocks EXT-31).
