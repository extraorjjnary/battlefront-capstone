# Local LBC flow simulation

No LBC simulation is implemented. Any future mock should be a local/test-only test double for an explicitly approved LBC domain flow, based on the selected product's verified merchant contract. Do not invent a Battlefront booking sequence and call it LBC-compatible merely because the public consumer flow is visible.

The mock must make no outbound LBC or PayMongo calls, require no credentials, fail closed unless explicitly enabled in local/testing, and refuse mutation in production. Suggested variables such as `LBC_MODE` and `LBC_ENABLED` are examples only and have no effect today. Never make the mock real by changing a base URL.

Once the product/flow is approved, the simulation should model only the confirmed concepts needed by the project: serviceability/quote result, selected handoff mode, booking outcome, provider identifier types and issuance timing, physical handoff, tracking updates, and any approved collection/remittance events. Until verified, use deterministic synthetic values and label them as simulated; do not mimic real LBC tracking-number formats or claim that LBC accepted a shipment.

## Implementation boundaries after separate approval

- Reuse canonical address/order data only where the selected LBC contract requires it. Do not duplicate recipient PII or store raw payloads without approved retention/security design.
- Keep Battlefront Order, Shipment, payment, inventory, sales, carrier booking, buyer collection, and seller remittance states separate.
- Use documented LBC idempotency/lookup behavior for timeout recovery. If not available, design explicit operator reconciliation before any retry; do not invent safe retry semantics.
- Keep provider calls outside database transactions. Use existing authorization and order/shipment locking only after the verified LBC flow has been mapped to those boundaries.
- Simulate failures through server-side test configuration, never request-selected flags. Local simulation notifications must never reach real email or push destinations.
- Preserve manual shipment registration and historical order/payment workflows unless a separate approved scope changes them.

## Future test acceptance

After implementation approval, verify the mock sends no HTTP requests (`Http::preventStrayRequests()` and `Http::assertNothingSent()`), fails closed outside allowed environments, refuses production mutation, preserves fake provenance, and accurately simulates the approved flow. Cover quote/serviceability, booking, identifiers, handoff/tracking, duplicate/concurrent requests, response loss and reconciliation, payment/collection separation, cancellation if supported, authorization, notifications, and legacy/manual compatibility.

Review any forward-only, data-preserving migration before separately authorized local database migration. Never reset, truncate, or replace development/customer/order/payment history. PayMongo automated refunds remain outside its approved first-release scope; carrier cancellation never marks a refund complete.
