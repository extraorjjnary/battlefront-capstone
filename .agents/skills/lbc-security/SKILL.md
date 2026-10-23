---
name: lbc-security
description: Review Battlefront local mock shipping isolation, authorization, payment trust, replay defense, privacy, cancellation races, and future LBC adapter security boundaries.
---

# LBC shipping security review

For every LBC review, first read the current `docs/integrations/lbc/README.md`, `api-sources.md`, `capability-matrix.md`, and `open-questions.md`; then read `security.md`, `integration-scope.md`, `architecture.md`, `shipping-lifecycle.md`, `payment-coordination.md`, `mock-development.md`, and `sandbox-checklist.md` as relevant. Do not rely on prior conversation context alone, and reread any LBC guidance changed during the task. Also read `docs/paymongo/06-security.md`, `10-readiness-audit.md`, and `11-system-aligned-setup.md`, applicable repository rules, and actual mutation/presentation boundaries.

- Verify local/test-only execution, explicit enablement, fake tracking identifiers, and zero outbound LBC requests. Environment configuration alone must not allow production mock fulfillment.
- Check ownership and existing staff permissions at every exposed action. Reject forged references, foreign shipment IDs, status skipping, and attempts to overwrite historical/manual references.
- Verify persisted-payment trust by flow: prepaid booking requires verified payment, while the approved COD shipment progression may remain unpaid through delivery and uses explicit collection confirmation. COD eligibility for a future mock-booking operation remains undecided. Check Order-before-Shipment locking, duplicate prevention, rollback on failures, and cancellation races. Shipping must not bypass PayMongo reconciliation or change payment, refunds, inventory, or sales independently.
- Apply the current order-cancellation cutoff from `.ai/rules/controllers-js-pages-orders.md`; do not rely on older EXT-89 permission to cancel after handoff. Review mock-booking cancellation as a separate operation.
- Check notification isolation and data minimization. Do not expose addresses, contact details, payment proof paths, credentials, or full external payloads in logs/customer responses beyond authorized needs.
- No LBC or PayMongo API calls, credentials, webhook registration, tunnels, production setup, or live shipments are permitted in the local mock scope. Do not invent a webhook signature mechanism for an undocumented future carrier provider.
- For future adapters, require official authentication/signature/replay/error evidence and explicit sandbox approval; document unknowns rather than treating mocks as security validation of LBC.
- Compare integration claims and trust boundaries with the LBC source audit/capability matrix. Public user flows do not prove merchant API authentication, booking semantics, tracking authenticity, settlement, or cancellation behavior.
- Verify that PayMongo-origin order cancellation retains PayMongo's reconcile-and-confirm-closure gate, including separate Hosted Checkout and online-banking behavior, and that a booking cancellation cannot close payment, restore stock, or claim refund. Preserve historical manual GCash/Maya review. Keep external calls outside retried database transactions and preserve immutable amount/delivery snapshots.

Report prioritized findings with file/line evidence, realistic reproduction, and remaining limits. Tests and review do not replace human approval.
