# Phased implementation roadmap

The developer has approved PayMongo implementation within the documented replacement scope. This roadmap does not authorize production credentials, live transactions, dashboard changes, webhook registration, or deployment. Resolve the relevant design questions before implementing affected behavior.

`11-system-aligned-setup.md` resolves the local database, service-secret location, queue/scheduler conventions, starting retry/timeouts, financial history preservation and existing role/refund workflow choices. Use those decisions in each phase; only deployment/account/provider facts still require external evidence. The private credential guide does not activate payment processing.

| Phase | Work | Exit gate |
|---|---|---|
| 0. Foundation review | Confirm project instructions, approved manuscript/context, `09-decisions-and-open-questions.md`, agent roles, Linear issue, current provider docs, and external readiness gates. | Approved implementation baseline is recorded; test credentials and account method availability are handled in test setup. |
| 1. Detailed design | Map Hosted Checkout v2 for card/GCash/Maya/QR Ph and the separate Payment Intent redirect flow for Direct Online Banking. Finalize order/payment state mapping, one-provider-flow retries, 24-hour cutoff/reconciliation, stock restoration, existing-order cutover, webhook inbox, audit retention, refund boundary, and MySQL concurrency design. | Architecture/security review complete; target MySQL version and scheduler/queue behavior confirmed before migration/job implementation. |
| 2. Persistence and domain boundary | Add additive migration/model/status mapping with data-preserving compatibility for attempts, event dedupe, audit history, and any approved refund records. | Migration reviewed against MySQL and legacy records; focused tests pass. |
| 3. Provider client and payment creation | Server-side test-key clients for the approved Checkout Session and Direct Online Banking flows, validated errors, idempotent create, order association. | Automated fake tests; no secret leakage; test-mode method support verified. |
| 4. Webhook and state transitions | HTTPS route, raw-body signature verifier, durable dedupe/inbox, flow-specific event mapping, separate payment/order transitions, 24-hour reconciliation and safe stock restoration. | Security review and duplicate/race/failure tests pass. |
| 5. Customer UX and compatibility | Add the approved online methods and return/status UI. At approved cutover, replace manual GCash/Maya proof upload/review for new online orders; retain historical records and preserve cash pickup, physical card-at-store, COD, fulfillment, and web/API rules. | End-to-end acceptance and existing workflows verified. |
| 6. Test-mode validation | Test every enabled card, wallet, QR Ph, and bank route; provider receipts; native method expiry; 24-hour closure races; retries; missed/duplicate events; recovery; and legacy compatibility with test credentials only. Fees are absorbed. Use a public HTTPS test endpoint only after explicit authorization; do not expose the database. | Redacted evidence, defect disposition, developer acceptance. |
| 7. Production readiness (separately approved) | Account verification, live secrets/webhook, monitoring, support, rollback and controlled launch. | Explicit production authorization; currently out of scope. |

Each implementation slice must name its G01-G12 acceptance criteria from `10-readiness-audit.md`, touched web/API/admin entry points, source evidence, migration implications, and required tests. Phase 1 must settle operation/inbox recovery, active-flow enforcement, money conversion, cancellation serialization, retained-data access and operational retry/retention values before dependent work. Pure local design/fakes do not require production activation; external test evidence is obtained only at its authorized phase.

The cutover/rollback plan must disable new starts while retaining ingress and reconciliation for existing flows; historical manual orders stay identifiable by persisted origin. Do not rely on turning off the webhook or deleting tables as rollback.

Do not combine phases into one broad issue. Keep payment implementation separate from production activation, credentials, and live transactions. This preparation request starts none of these implementation phases.
