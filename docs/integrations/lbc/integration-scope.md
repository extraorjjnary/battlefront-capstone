# LBC preparation scope

This preparation refreshes the integration discussion around LBC's publicly described shipment and ecommerce flows and the evidence needed for a merchant integration. It includes a read-only repository/system mapping and public official-source audit dated 2026-10-24.

This document is preparation, not implementation approval. It does not itself authorize code, database, route, UI, credential, merchant, booking, operational API, tunnel, or production changes. A separately approved internal simulation may use synthetic Battlefront behavior and no external calls; provider-compatible simulation or adapter work requires verified contract evidence and separate authorization.

The preparation does not assume the existing Battlefront shipment lifecycle is the correct LBC flow. It maps LBC's public sequence—shipment inputs, product/service selection, rate/valuation, rider pickup or branch drop-off, shipping-fee settlement, booking/identifiers, physical acceptance, tracking, and delivery—and treats LBC COD/COP collection/remittance as a separate financial flow. The exact flow must be verified before provider-compatible implementation; an internal simulation remains Battlefront-only behavior.

Battlefront's current Orders, Shipments, checkout snapshots, COD/pickup workflows, PayMongo states, inventory, sales, notifications, and manual registration remain compatibility constraints. Whether these can support the LBC flow without changes is an open design question. Review [system mapping](architecture.md), [capability evidence](capability-matrix.md), [decisions/evidence](open-questions.md), and [readiness gates](sandbox-checklist.md).

Internal simulation, provider-compatible simulation, sandbox adapter, and production integration are distinct stages. Local simulation is outbound-free and cannot claim LBC compatibility. No checkout recalculation, payment processing, COD/COP settlement, stock, sales, or refund change is authorized by this preparation.
