---
name: lbc-testing
description: Design and run Battlefront shipping tests for approved local simulations and, when separately authorized, documented LBC adapters.
---

# LBC mock verification

Use `docs/integrations/lbc/README.md` as the entry point and follow its task-to-reference table. Read `testing-best-practices`, applicable `.ai/rules`, adjacent shipment/payment tests, and PayMongo references when testing payment coordination. Use the installed Pest version.

- Test observable behavior: canonical and invalid addresses, mock quote amounts, fake references, booking replay/conflict, sequential tracking, cancellation cutoffs, simulated failure atomicity, and disabled/nonlocal environment rejection.
- Cover guests, unauthorized staff, foreign customers, authorized operators, and order ownership at actual exposed request boundaries. Do not invent an endpoint solely for tests.
- Prove prepaid booking rejects pending/rejected/unresolved payment and accepts persisted verified payment. Exercise existing COD shipment progression separately; until mock-booking eligibility is decided, do not encode an assumed COD booking result. Preserve pickup exceptions and PayMongo behavior; neither a return URL nor tracking status verifies payment.
- Assert the current order-cancellation cutoff: customers may cancel only their own Pending orders with Pending payment, no submitted wallet proof, and no shipment beyond `awaiting_preparation`; administrators may cancel Pending/Processing only through `ready_for_dispatch`. Mock-booking cancellation is separate and cannot bypass order or PayMongo closure rules.
- Carrier-only mock tests must prove no outbound LBC or PayMongo traffic. When testing order-cancellation coordination, use HTTP fakes for required PayMongo reconciliation and closure while preventing real traffic. Distinguish method expiry from the merchant cutoff and test paid, unresolved, unsupported-closure, late-payment, and confirmed-closure outcomes. A mock carrier result never satisfies PayMongo closure or refund assertions.
- Isolate notifications. Internal simulation tests need no merchant account or credentials and use synthetic references. Sandbox tests require separate authorization and approved test credentials; never create a real shipment.
- Check duplicate prevention and state effects independently: shipment identity, payment/order state, stock, sales, and notification count. For concurrency claims, distinguish SQLite sequential coverage from actual MySQL locking evidence.
- Preserve tests and test-storage isolation. Run the narrow affected suite first; report uncovered requirements rather than claiming a mock proves carrier compatibility, settlement, or production readiness.
- Derive provider assertions only from a verified contract. Keep internal mock behavior, public-flow assumptions, contract behavior, and merchant entitlement distinct; never infer API states or settlement from consumer-page copy.

Deliver test-to-requirement coverage, exact results, and any separate future sandbox acceptance gates.
