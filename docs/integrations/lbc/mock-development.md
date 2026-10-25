# Local LBC flow simulation

No LBC simulation is implemented. An explicitly approved internal Battlefront simulation may model synthetic local behavior without a merchant contract. Identify its inputs, outputs, identifiers, and state transitions as Battlefront test behavior; do not call it LBC-compatible or derive provider status semantics from public consumer pages. A provider-compatible simulation requires the selected product's verified merchant contract.

The mock must make no outbound LBC or PayMongo calls, require no credentials, fail closed unless explicitly enabled in local/testing, and refuse mutation in production. Suggested variables such as `LBC_MODE` and `LBC_ENABLED` are examples only and have no effect today. Never make the mock real by changing a base URL. A task testing order-cancellation payment coordination may use HTTP fakes for PayMongo reconciliation while preventing real requests; this does not change the carrier-only no-network requirement.

Once internal behavior is approved, the simulation should model only concepts needed by that assignment. Use deterministic synthetic values; do not mimic real LBC tracking-number formats or claim LBC acceptance. Model provider identifiers, states, or collection/remittance only when verified for the selected product and included in approved scope.

## Implementation boundaries after separate approval

- Reuse canonical address/order data only where the selected LBC contract requires it. Do not duplicate recipient PII or store raw payloads without approved retention/security design.
- Keep Battlefront Order, Shipment, payment, inventory, sales, carrier booking, buyer collection, and seller remittance states separate.
- For provider-compatible behavior, use documented idempotency/lookup semantics for timeout recovery. If unavailable, require an approved reconciliation design before retry. Internal simulation retry behavior must be labeled as local behavior and cannot imply provider guarantees.
- Keep provider calls outside database transactions. Use existing authorization and order/shipment locking only after the verified LBC flow has been mapped to those boundaries.
- Simulate failures through server-side test configuration, never request-selected flags. Local simulation notifications must never reach real email or push destinations.
- Preserve manual shipment registration and historical order/payment workflows unless a separate approved scope changes them.

## Future test acceptance

After implementation approval, verify the mock sends no HTTP requests (`Http::preventStrayRequests()` and `Http::assertNothingSent()`), fails closed outside allowed environments, refuses production mutation, preserves fake provenance, and accurately simulates the approved flow. Cover quote/serviceability, booking, identifiers, handoff/tracking, duplicate/concurrent requests, response loss and reconciliation, payment/collection separation, cancellation if supported, authorization, notifications, and legacy/manual compatibility.

Review any forward-only, data-preserving migration before separately authorized local database migration. Never reset, truncate, or replace development/customer/order/payment history. PayMongo automated refunds remain outside its approved first-release scope; carrier cancellation never marks a refund complete.
