# LBC integration readiness gates

This checklist prepares future design and review. It does not authorize contacting LBC, registering a merchant account, obtaining credentials, calling APIs, booking shipments, or deploying integrations. Public consumer flows are useful discovery evidence, not an API contract.

## Gate 1: select the business flow

- Decide which LBC product/channel Battlefront intends to use: authorized merchant API, LBC Online/manual workflow, or branch-based manual transaction. Do not automate the public website as an API.
- Decide sender handoff mode (rider pickup or branch drop-off) and identify who prepares, pays for, hands over, and confirms acceptance of parcels.
- Decide how shipping costs relate to Battlefront checkout: live carrier rate, disclosed estimate, or absorbed cost; define fee variance ownership without repricing placed orders.
- Decide whether LBC COD/COP is in scope. If so, define buyer collection evidence, seller remittance reconciliation, fees, partial/failed collection, order/payment completion, and accounting separately from current Battlefront COD/pickup.
- Define parcel granularity and data ownership: one or multiple packages; source for weight/dimensions/content/declared value; and how addresses map to LBC serviceability and identifiers.
- Record these choices and obtain developer scope approval before implementation planning.

## Gate 2: obtain official merchant/API evidence

- Obtain authorized merchant access and endpoint-specific official documentation for the selected product and account.
- Verify API product entitlement, authentication, permissions, credential lifecycle, environment separation, support ownership, and fixed hosts.
- Confirm the API represents the selected public/business flow: serviceability, rates/valuation, booking, pickup/drop-off, identifiers, shipment acceptance, tracking, cancellation, COD/COP settlement if applicable.
- Record request/response schemas, required package and address identifiers, fees and quote validity, errors/rate limits, idempotency/lookup, partial-failure recovery, state semantics, and data retention/privacy rules.
- If events/webhooks exist, verify signature and raw-body requirements, event identity, replay protection, ordering, retry behavior, and reconciliation alternative.
- Confirm sandbox host isolation, permitted services/test identities, and written assurance that calls cannot trigger real pickup, shipment, label, or charge.
- Record source URLs, contract/document version and access date, merchant entitlement, limitations, and unresolved evidence. Do not infer details from catalog item names or Azure branding.

## Gate 3: map and review the integration design

- Map each verified LBC stage to Battlefront order, shipment, payment, inventory, notification, and sales responsibilities. Treat booking, identifier issue, handoff, tracking, delivery, collection, and remittance as distinct events.
- Preserve the immutable order/fee snapshots and the current PayMongo reconciliation/confirmed-flow-closure rules. LBC COD/COP settlement cannot be inferred from a delivery status or substituted for PayMongo payment evidence.
- Reuse existing authorization and locking only after confirming they fit the provider stages. Review one-shipment-per-order compatibility, multiple tracking identifiers, booking provenance, duplicate prevention, retries, cancellation races, and operator reconciliation.
- Keep provider calls outside database transactions; define durable recovery for timeouts and partial outcomes from the verified LBC contract.
- Approve interfaces, persistence and forward-only migration impact, UX/operator workflow, privacy/logging, rollout, and tests before implementation.

## Gate 4: controlled sandbox verification

After separate sandbox implementation approval, test serviceability/rates, booking, pickup/drop-off, identifier issuance, physical handoff, tracking updates, cancellation if supported, COD/COP settlement if approved, authorization, duplicate/concurrent requests, response loss, retries, late/out-of-order events, outages, partial failures, and operator recovery. Use synthetic customer/package data and confirm no live shipment or charge is created.

Keep local simulation outbound-free and clearly synthetic. Do not make it real by changing a URL. Preserve legacy/manual shipment behavior, local notification isolation, and existing order/payment rules. Never reset or replace customer/order/payment history.

## Production gate

Production requires separate approval, production contracts and credentials, operational monitoring, reconciliation/support ownership, privacy review, incident handling, and deployment review. Sandbox success does not approve production shipping.
