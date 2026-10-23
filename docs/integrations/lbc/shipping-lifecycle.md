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

LBC COD is a carrier collection and remittance service. It is not automatically equivalent to Battlefront's current COD flow, where the order remains unpaid until explicit Battlefront collection confirmation. LBC COP is a branch-pickup collection flow, not Battlefront store pickup. Select either service only after deciding settlement ownership, buyer amount, fees, reconciliation evidence, and how orders/sales are recorded.

Keep order cancellation, LBC booking cancellation, payment-flow closure, refunds, and stock restoration as separate operations. Current Battlefront order cancellation is prohibited at or after `handed_to_lbc`; PayMongo-origin cancellation before that cutoff still requires latest-state reconciliation and confirmed applicable flow closure before stock restoration. LBC cancellation cannot satisfy a PayMongo closure requirement, and an LBC COD remittance is not automatically equivalent to a verified Battlefront payment event.

## Local simulation boundary

If a local mock is approved, it should simulate the selected, evidenced domain flow with clearly synthetic identifiers and statuses. It must not claim LBC acceptance or send external requests. Until the LBC merchant flow and Battlefront policy are approved, do not encode COD/COP, booking cancellation, identifier, retry, or status-mapping assumptions as integration behavior. See [system mapping](architecture.md) and [decision register](open-questions.md).
