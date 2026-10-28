# LBC integration security boundaries

- This preparation added no carrier credentials, application code, or outbound LBC requests. Public LBC flows are not authorization to call their services.
- Select the merchant product and obtain its official authentication, entitlement, privacy, and event contract before designing credentials or requests. Never infer auth from Azure branding or an API catalog name.
- Keep credentials server-side, environment-separated, rotated, and out of source, logs, browser code, docs, and commits. Use fixed approved hosts for any separately approved sandbox/production adapter.
- Revalidate order, shipment, user authorization, and provider state at mutation boundaries. Apply existing ownership and administrator permission rules; do not trust browser redirects, user-supplied tracking states, or fabricated carrier evidence.
- Keep booking, booking ID, tracking/waybill IDs, physical handoff, tracking progress, delivery, Battlefront payment, LBC buyer collection, and seller remittance distinct. Do not infer payment or settlement from shipment status.
- Use documented idempotency and lookup/reconciliation semantics for booking timeouts. If the provider contract does not resolve uncertain outcomes, require operator reconciliation before retrying.
- Keep provider calls outside database transactions. Record enough typed provenance to prevent reference overwrites and support reconciliation, but avoid duplicate recipient addresses and unapproved raw payload retention.
- Preserve saved order totals and delivery-fee snapshots. A later carrier quote or charge cannot silently reprice an order. LBC COD/COP requires a separate reviewed collection/remittance design; it is not equivalent to the current Battlefront COD/pickup workflow.
- Preserve PayMongo authenticated event and cancellation rules. Carrier responses cannot establish PayMongo payment, close a Checkout Session/Payment Intent, restore stock, or represent a refund.
- Follow the current Battlefront cancellation cutoff before `handed_to_lbc`; the historical after-handoff wording in `.ai/rules/models-js-pages-orders.md` is superseded by the current order-processing rule.
- Keep an approved internal simulation outbound-free, disabled by default, confined to local/testing, conspicuously synthetic, and unable to send real notifications. Production must refuse mock mutations. It need not have an LBC contract but cannot claim LBC compatibility.
- For a future adapter, review authorization, webhook signature/raw-body verification, replay defense, privacy/retention, logging, retries, cancellation, reconciliation, and operator recovery against the actual official contract.
