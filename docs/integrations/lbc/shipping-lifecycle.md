# LBC shipment lifecycle preparation

LBC's public flow describes shipment details and service selection, a sender handoff choice (rider pickup or branch drop-off), booking, package acceptance, transport/tracking, and delivery. LBC COD/COP adds buyer collection and seller remittance. This is a domain-level flow from public pages; merchant API states, required ordering, and transition semantics remain unknown until documented for the selected account/product.

## Keep these events distinct

1. **Quote/serviceability:** LBC says whether the requested shipment can be served and provides a rate/fee/valuation result. Battlefront's current local estimate is not this result.
2. **Booking submitted/confirmed:** a booking has been requested or confirmed. Confirmation does not by itself prove the parcel was physically accepted by LBC.
3. **Identifier issued:** record the identifier type and issuance point. Public materials indicate that booking and tracking identifiers may differ by channel; do not assume a single reference.
4. **Handoff scheduled/accepted:** distinguish an expected rider visit or branch drop-off from LBC physically receiving the parcel.
5. **Tracking progress:** consume only authenticated/documented provider statuses/events or a documented reconciliation lookup. Do not treat public page labels as an API status enum.
6. **Delivered:** provider delivery evidence and Battlefront Order completion/payment are separate decisions.
7. **COD/COP collection and remittance:** if using LBC collection, track buyer-paid/collected and seller settlement/reconciliation separately from parcel delivery and Battlefront payment state.

The actual integration state machine and mapping to Battlefront's lifecycle must be derived from the chosen LBC contract. Do not collapse booking into `ready_for_dispatch`, or assume the existing `handed_to_lbc → in_transit → out_for_delivery → delivered` milestones have one-to-one LBC equivalents.

## Payment and cancellation boundaries

LBC COD is a carrier collection and remittance service. Battlefront's current COD workflow records manual collection after delivery and separately requires full remittance reconciliation before payment verification, completion, or sale. Those manual records do not establish LBC settlement or API support. LBC COP is a branch-pickup collection flow, not Battlefront store pickup. Any provider service still requires verified merchant evidence for settlement ownership, buyer amount, fees, and reconciliation.

Keep order cancellation, LBC booking cancellation, payment-flow closure, refunds, and stock restoration as separate operations. Current Battlefront order cancellation is prohibited at or after `handed_to_lbc`; approved PayMongo-origin cancellation requirements still call for latest-state reconciliation and confirmed applicable-flow closure before stock restoration; verify implementation before describing this as current runtime behavior. LBC cancellation cannot satisfy a PayMongo closure requirement, and an LBC COD remittance is not automatically equivalent to a verified Battlefront payment event.

## Local simulation boundary

Battlefront's local simulation creates a synthetic booking reference only for eligible configured-rate shipments. It does not simulate carrier acceptance, tracking, COD/COP collection, or remittance and cannot claim LBC compatibility. Do not encode booking cancellation, provider identifiers, retries, or status mappings without a verified merchant contract and approved product policy. See [system mapping](architecture.md) and [decision register](open-questions.md).
