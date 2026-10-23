---
name: lbc-shipping-domain
description: Design or review Battlefront flows against LBC's documented shipment lifecycle, including local mocks and future integration planning, while preserving existing order workflows.
---

# Battlefront shipping domain

For every LBC domain task, first read the current `docs/integrations/lbc/README.md`, `api-sources.md`, `capability-matrix.md`, and `open-questions.md`; do not rely on prior conversation context alone. Then read `docs/CAPSTONE_CONTEXT.md`, applicable `.ai/rules`, and the task-relevant LBC `integration-scope.md`, `architecture.md`, `shipping-lifecycle.md`, `payment-coordination.md`, `security.md`, `mock-development.md`, and `sandbox-checklist.md`. Re-read changed LBC docs if they are updated during the task. Also read `docs/paymongo/03-architecture.md`, `05-payment-flow.md`, `10-readiness-audit.md`, and `11-system-aligned-setup.md`. Use graphify before source exploration as required by AGENTS.md. Current approved rules and PayMongo decisions supersede stale issue-era guidance; identify any remaining conflict explicitly.

- Trace existing `Shipment`, `OrderProcessingService`, delivery quoting/address validation, presenters, and notification publisher before proposing another module.
- Start domain modeling from the selected, documented LBC product flow in `api-sources.md` and `architecture.md`. Separate public customer/merchant flows from the API contract and account entitlement; never force LBC stages into Battlefront's current state machine without an explicit mapping.
- Keep payment, order, and shipment states distinct. Prepaid fulfillment requires persisted verified payment; COD delivery may progress while payment is pending and requires explicit collection confirmation after delivery. Browser redirects, tracking events, and mock outcomes never establish payment.
- Preserve approved COD and pickup exceptions, historical manual payments, manual shipment registration, immutable quote snapshots, and existing cancellation/stock/sales behavior. COD eligibility for the proposed mock-booking action is unresolved and must be surfaced for developer decision. Delivery alone must not verify COD collection.
- Serialize mutations on Order before Shipment. Prevent duplicate booking and duplicate lifecycle effects, including replay and concurrent requests.
- Treat Battlefront mock quotes as test assumptions, not official LBC rates or serviceability. Reuse canonical address validation only as a local check; it does not establish carrier coverage. Include LBC package data, handoff, identifier, tracking, and any collection/remittance stages when mapping a verified flow.
- Keep mock identifiers clearly fake and prevent mock execution in production. Separate mock-booking cancellation from order cancellation, stock release, payment closure, and refunds. Current order rules prohibit cancellation at or after `handed_to_lbc`; do not repeat older EXT-89 wording that allowed it.
- For PayMongo-origin order cancellation, require PayMongo reconciliation and confirmed supported closure before stock release. Keep Checkout Session and online-banking Payment Intent closure flow-specific; native method expiry differs from the 24-hour merchant cutoff. Unresolved/unsupported closure retains stock. Preserve historical manual GCash/Maya proof/review. Do not reprice saved totals or delivery fees; cancellation is not a refund.
- Inspect schema before proposing persistence; prefer existing shipment structures. Present concrete migration and compatibility implications, and stop before major database, checkout, or payment changes until approved.

Deliver the existing-to-proposed mapping, transition ownership, failure behavior, approval boundaries, and unresolved future carrier requirements.
