# LBC preparation scope

This preparation refreshes the integration discussion around LBC's publicly described shipment and ecommerce flows and the evidence needed for a merchant integration. It includes a read-only repository/system mapping and public official-source audit dated 2026-10-24.

No LBC or PayMongo application operation is implemented. No code, database migration, route, UI, configuration, credential, merchant registration, booking, operational API request, tunnel, test, or production change is included or authorized.

The preparation does not assume the existing Battlefront shipment lifecycle is the correct LBC flow. It maps LBC's public sequence—shipment inputs, product/service selection, rate/valuation, rider pickup or branch drop-off, shipping-fee settlement, booking/identifiers, physical acceptance, tracking, and delivery—and treats LBC COD/COP collection/remittance as a separate financial flow. The exact flow must be verified for Battlefront's selected merchant product before implementation.

Battlefront's current Orders, Shipments, checkout snapshots, COD/pickup workflows, PayMongo states, inventory, sales, notifications, and manual registration remain compatibility constraints. Whether these can support the LBC flow without changes is an open design question. Review [system mapping](architecture.md), [capability evidence](capability-matrix.md), [decisions/evidence](open-questions.md), and [readiness gates](sandbox-checklist.md).

Any future local simulation, merchant sandbox adapter, and production integration are separate stages. A local mock must remain outbound-free and cannot claim LBC compatibility; sandbox and production require separate approvals and authorized evidence. No checkout recalculation, payment processing, COD/COP settlement, stock, sales, or refund change is authorized here.
