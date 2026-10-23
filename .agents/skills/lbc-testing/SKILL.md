---
name: lbc-testing
description: Design and run Battlefront shipping tests from documented LBC flows for local mocks or approved adapters, without real carrier traffic.
---

# LBC mock verification

For every LBC testing task, first read the current `docs/integrations/lbc/README.md`, `api-sources.md`, `capability-matrix.md`, and `open-questions.md`; do not rely on prior conversation context alone. Then read `integration-scope.md`, `architecture.md`, `shipping-lifecycle.md`, `payment-coordination.md`, `security.md`, `mock-development.md`, and `sandbox-checklist.md` as relevant. Reread LBC docs changed during the task. Also read `testing-best-practices`, applicable `.ai/rules`, `docs/paymongo/10-readiness-audit.md`, and `11-system-aligned-setup.md`. Inspect adjacent shipment/payment tests and use the installed Pest version.

- Test observable behavior: canonical and invalid addresses, mock quote amounts, fake references, booking replay/conflict, sequential tracking, cancellation cutoffs, simulated failure atomicity, and disabled/nonlocal environment rejection.
- Cover guests, unauthorized staff, foreign customers, authorized operators, and order ownership at actual exposed request boundaries. Do not invent an endpoint solely for tests.
- Prove prepaid booking rejects pending/rejected/unresolved payment and accepts persisted verified payment. Exercise existing COD shipment progression separately; until mock-booking eligibility is decided, do not encode an assumed COD booking result. Preserve pickup exceptions and PayMongo behavior; neither a return URL nor tracking status verifies payment.
- Assert the current order-cancellation cutoff: customers may cancel only Pending orders with Pending payment and no shipment beyond `awaiting_preparation`; administrators may cancel Pending/Processing only through `ready_for_dispatch`. Mock-booking cancellation is separate and cannot bypass order or PayMongo closure rules.
- For gateway orders, distinguish provider method expiry from the 24-hour order cutoff. Order cancellation/stock restoration requires reconciled latest provider state and confirmed supported flow closure; test paid, pending/processing, uncertain, unsupported-closure, late-payment, and closure-confirmed paths as separate outcomes. A mock carrier result must never satisfy PayMongo closure or refund assertions.
- Assert shipping paths make no outbound LBC or PayMongo requests with HTTP fakes/stray-request prevention, and isolate notification delivery. No merchant account, credentials, live waybills, or real shipments are needed.
- Check duplicate prevention and state effects independently: shipment identity, payment/order state, stock, sales, and notification count. For concurrency claims, distinguish SQLite sequential coverage from actual MySQL locking evidence.
- Preserve tests and test-storage isolation. Run the narrow affected suite first; report uncovered requirements rather than claiming a mock proves carrier compatibility, settlement, or production readiness.
- Derive integration scenarios from the selected LBC flow and verified contract. Keep tests for public-flow assumptions, mock behavior, API contract behavior, and merchant entitlement clearly distinct; never invent API statuses or settlement results from consumer-page copy.

Deliver test-to-requirement coverage, exact results, and any separate future sandbox acceptance gates.
