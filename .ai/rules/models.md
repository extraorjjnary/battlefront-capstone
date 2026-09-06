---
paths:
  - 'database/migrations/**,app/Models/**'
---

# Models

## Store user roles as strings with an enum cast
Store users.role in MySQL as a bounded string with customer as the default, not as a database-native ENUM. Use App\Enums\UserRole as the Eloquent cast and the application source of allowed role values.

## Apply the completed EXT-6 schema decisions
Use the manuscript ERD plus EXT-6's approved implementation decisions: Laravel auth infrastructure, unsigned big integer identifiers, forecasts.method, and is_active=true for categories, products, and chatbot knowledge. Do not use lifecycle enums or soft deletes; filter inactive records from customer/chatbot flows, retain history, and allow reactivation. Explicit compatibility checking and compatibility persistence are out of scope. Payment methods are deferred to EXT-80 (blocks EXT-32); order snapshot fields are deferred to EXT-81 (blocks EXT-31).
