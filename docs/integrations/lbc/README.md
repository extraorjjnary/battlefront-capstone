# LBC integration preparation

This packet prepares future LBC integration design from LBC's published shipping and ecommerce flows. It does not implement an integration or authorize LBC contact, merchant registration, credentials, API calls, bookings, tunnels, or production changes. Public LBC pages describe customer/merchant service flows; they do not establish Battlefront's merchant API contract or entitlement. See the [official source audit](api-sources.md) and [capability matrix](capability-matrix.md), checked 2026-10-24.

## Start from LBC's flow

LBC's public sender flow is organized around a shipment transaction: supply sender and recipient details, origin/destination and package information; choose rider pickup or branch drop-off; determine the service/rate and how the shipping fee is settled; book and prepare the package; hand it to the rider or branch; then track it using the LBC-issued shipment identifier. LBC separately offers COD and COP, where LBC collects from the buyer and remits seller proceeds. These paths must not be collapsed into one generic “book shipment” operation.

The portal publicly advertises booking/tracking and has indexed API names/descriptions, but the actual merchant operations and contracts were not readable in this audit. Therefore the flow above is a domain discovery baseline from public LBC materials, not an API sequence. The chosen merchant product may differ and must be verified from authorized endpoint-specific documentation.

## Preparation priorities

1. **Identify the actual LBC product and merchant flow.** Establish whether Battlefront will use a corporate/merchant API, LBC Online, branch transactions, rider pickup, or another contracted offering. Do not assume the public consumer flow is the API contract.
2. **Map LBC shipment inputs and responsibilities.** Capture sender/recipient, location identifiers, package contents/weight/dimensions/value, shipping service, handoff mode, rate/valuation, fee payer, booking/waybill/tracking identifiers, and physical acceptance events. Compare each with existing Battlefront data and identify missing information.
3. **Separate transport from collection and settlement.** Decide whether Battlefront uses ordinary shipping with Battlefront-owned payment handling, LBC COD, LBC COP, or no carrier collection service. LBC COD/COP involve LBC collecting the buyer amount and remitting seller proceeds; this changes payment, reconciliation, and order-completion responsibilities.
4. **Obtain the merchant API contract.** Verify authorization, product entitlement, sandbox, schemas, state/status semantics, rates, serviceability, booking, tracking, cancellation, retries, errors, and any webhook/reconciliation mechanisms from official endpoint-specific documentation.
5. **Map the verified flow into Battlefront.** Only after the product/contract and business choices are approved, map provider events to current orders, shipments, payment states, inventory, notifications, and accounting. Do not assume the existing manual lifecycle already matches LBC's stages.
6. **Approve implementation and test scope separately.** Keep local simulation, sandbox integration, and production as separate stages and approvals. The [sandbox checklist](sandbox-checklist.md) records evidence and test gates.

## Existing system constraints to preserve during mapping

Battlefront currently has one Shipment per order, manual shipment registration and progression, immutable delivery/order snapshots, COD and pickup payment exceptions, and PayMongo-specific reconciliation/cancellation gates. These are compatibility constraints to map against the verified LBC flow, not evidence that the integration should simply reuse every existing step unchanged. See [system mapping](architecture.md), [payment coordination](payment-coordination.md), and [open decisions](open-questions.md).

Follow current approved project rules and PayMongo readiness decisions where they govern existing behavior. Current order cancellation stops before `handed_to_lbc`; `.ai/rules/models-js-pages-orders.md` has older after-handoff wording and needs separate rule maintenance. No checkout, payment, stock, or refund behavior changes are authorized by this preparation.
