---
name: lbc-api-docs
description: Research official LBC capabilities, merchant access, and documented API contracts for Battlefront shipping. Use for LBC source verification or future adapter planning, not ordinary order administration.
---

# LBC documentation research

For every LBC task, first read the current `docs/integrations/lbc/README.md`, `api-sources.md`, `capability-matrix.md`, and `open-questions.md`; do not rely on prior conversation context alone. Then read the task-relevant `integration-scope.md`, `architecture.md`, `shipping-lifecycle.md`, `payment-coordination.md`, `security.md`, `mock-development.md`, and `sandbox-checklist.md`. If these documents change during the task, reread the affected guidance. Keep Battlefront product decisions separate from provider facts.

- Start with the source URLs and verified-flow summary in `docs/integrations/lbc/api-sources.md`; recheck official LBC sources when the task needs current evidence. Include https://lbcapiservice.lbcapps.com/, `/apis`, and `/products`, plus the official LBC shipment, rate, tracking, pickup/drop-off, and ecommerce COD/COP sources relevant to the claim. Record exact URLs, access date, evidence and limitations in the documentation.
- Distinguish publicly advertised services, accessible API operations, account-specific entitlement, and unresolved questions. An empty catalogue or inaccessible page does not prove a capability is unsupported.
- Public booking, rates, and tracking interfaces are not API contracts. Never infer endpoints, authentication headers, payloads, webhooks, idempotency, cancellation, limits, or sandbox access from those interfaces.
- Documentation research is read-only. Do not submit bookings, track real shipments through programmatic requests, obtain credentials, create merchant accounts, or call operational LBC APIs. Any future sandbox operation is a separate task requiring explicit authorization.
- Internal mock inputs and outputs must be identified as Battlefront contracts, not LBC schemas. Require official endpoint-specific evidence and separate authorization before a future sandbox adapter.

Deliver verified facts with citations, explicit unknowns, and the evidence required to resolve each unknown. Do not claim merchant access or production readiness.
