# System-aligned decisions and test credential setup

Reviewed 2026-10-23. Preparation only: these decisions guide a later implementation. No PayMongo configuration binding, route, job, migration or transaction has been added. This document supersedes earlier preparation statements that the local database version, queue conventions or refund-recording workflow were unknown.

## Where to put test credentials

Use the existing project-root `.env` at `C:\Users\mark\Desktop\battlefront-capstone\.env`. The file exists, matches the repository `.gitignore`, and is not tracked. Its contents were not printed or read for this preparation. Add these project-selected variable names locally, filling the values privately:

```dotenv
PAYMONGO_ENABLED=false
PAYMONGO_MODE=test
PAYMONGO_SECRET_KEY=
PAYMONGO_PUBLIC_KEY=
PAYMONGO_WEBHOOK_SECRET=
```

These names are our proposed configuration contract, not PayMongo-required environment variable names. They currently do nothing because the application has no PayMongo binding. Keep enabled false until the implementation and test setup are ready. Do not replace the existing application, database, mail or manual-wallet variables.

- Obtain the test API keys from the PayMongo Dashboard Developers/API Keys area in test mode. The secret key starts with `sk_test_`; a public test key starts with `pk_test_`. Put them only in the corresponding local entries. Hosted Checkout is server-created; do not expose a secret to Vue or mobile code. Keep a public key private in server configuration until a verified banking operation specifically requires client use.
- `PAYMONGO_WEBHOOK_SECRET` is the signing secret belonging to the separately configured test webhook endpoint. It is not the API secret key. Leave it empty until an authorized test endpoint exists; missing signing secret must eventually make the handler fail closed, never skip verification.
- Do not paste keys into chat, documentation, `.env.example`, committed files or `VITE_*` variables. Do not run commands that print `.env`, service configuration or authorization headers. A test key is still a credential.
- Do not add credentials to the existing GCash/Maya account-number variables; those are historical manual-wallet instructions and must remain available to existing orders.
- Later implementation will follow `config/services.php`'s existing service convention: environment values are read only in configuration and consumers use `config(...)`. Add empty placeholders to `.env.example` only as part of that later configuration task. Automated tests use synthetic values/fakes, never the real local test key.
- If local configuration was cached when implementation is ready, `php artisan config:clear` refreshes configuration on subsequent application boot. Restart long-running workers after credential/configuration changes. Do not print the loaded values to verify them; use boolean presence/mode checks. No cache/worker command was executed during preparation.

Official sources: [PayMongo authentication](https://docs.paymongo.com/reference/authentication), [testing](https://docs.paymongo.com/docs/payment-acceptance-testing), [webhook setup and signing](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management). Laravel's [configuration guidance](https://laravel.com/docs/13.x/configuration#configuration-caching) explains why `env()` belongs in config files and cached configuration needs refresh.

## Verified local baseline

| Area | Inspected evidence | Preparation decision |
|---|---|---|
| Runtime | Boost reports Laravel 13.30.1 and PHP 8.5; project instructions target PHP 8.4 | Preserve PHP 8.4 compatibility; do not introduce 8.5-only syntax. Confirm deployment runtime separately. |
| Database | Read-only query reports MySQL 8.0.46, REPEATABLE-READ; orders/order_items/shipments/inventories/jobs/failed_jobs use InnoDB | Design and validate concurrency against this existing MySQL baseline; SQLite alone is insufficient. No new database/dependency. |
| Secrets | Root `.env` is ignored and untracked; `config/services.php` already maps external-service secrets | Use the environment contract above and the existing services configuration convention. |
| Async | `config/queue.php` defaults to database, jobs/failed_jobs storage, retry_after 90 seconds, after_commit false | Use the database connection and a dedicated logical payments queue later; explicit after-commit dispatch plus durable inbox sweep. Do not change the global queue setting for all features. |
| Existing job conventions | Notification jobs have 3 tries, 30-second timeout, backoff 30/120 seconds; Expo HTTP uses connectTimeout 3 and timeout 10 | Adopt those starting budgets for one provider operation per job. Timeout must remain below effective queue retry_after. Never blindly retry a mutation without the verified operation contract. |
| Scheduling | `routes/console.php` registers existing daily prune commands | Add payment reconciliation schedules there in later implementation; do not create a second scheduler system. Existing registrations do not prove a worker/scheduler process is running. |
| Authorization | `docs/ADMINISTRATION_SETUP.md` and gated routes distinguish Owner, configurable Staff/Manager and technical IT | Business payment exceptions belong to Owner or appropriately permitted business staff. IT operates technical configuration but receives no blanket business payment privileges. |
| Refunds | `CustomerReturnController`, `HandleCustomerReturn`, customer_refunds/customer_refund_payments/customer_refund_payment_reviews and returns.record_payment/returns.reconcile routes already exist | Preserve existing manual refund records, independent review and reporting. No parallel refund subsystem or PayMongo automated refund API is introduced. |

The local SQL checks selected only server metadata/storage engines, not customer rows or credentials. Deployment settings still require environment-specific confirmation.

## Resolved design choices within existing conventions

1. Extend existing order Actions/Services and server presenters for both web/API. Keep controllers thin. Reuse order locking, commercial snapshots, cancellation stock restoration, and fulfillment/sales rules. Persist payment origin so historical manual GCash/Maya continue to use their original rules.
2. Use an additive per-order payment coordination/claim record with a unique order identity, flow history and separate provider attempts/operations/inbox. Lock the order before its coordination record and follow existing shipment/inventory order consistently. This is a design choice, not an applied migration. Keep network I/O outside retried transactions.
3. Use existing exact decimal handling for commerce and explicit minor-unit conversion at the provider boundary. Reconcile the full saved payable amount including delivery. Preserve existing merchandise-only sales reports and verified-refund treatment; provider fee/net settlement must not overwrite either.
4. Use 3-second connection/10-second request budgets, 30-second job budget, 3 tries and 30/120-second job backoff as initial settings matching current code. One job performs one bounded provider stage; persist progress between bank create/method/attach stages. Authentication/validation failures stop automatic retry; uncertain mutations recover the same operation only where supported. Exhaustion leaves a visible unresolved record.
5. Schedule a non-overlapping inbox/unknown-operation sweep every minute; select due work in bounded batches of 100 with a next-check time. Escalate unresolved work at 15 minutes to the permitted business operator/developer during development; keep safe reconciliation ongoing without unbounded immediate retries. The 1-minute/100-row/15-minute settings are selected project starting values, not provider standards. The merchant's separate 24-hour cutoff still requires confirmed provider closure.
6. Preserve minimal verified payment identifiers, immutable state transitions and existing financial history without automatic deletion in the first release, following current history preservation. Do not persist full raw payloads or credentials. Any later financial-history retention/deletion policy requires explicit review; this choice is not a legal retention determination. Recovery must work from verified minimal data plus authenticated provider retrieval.
7. Reuse existing return/refund workflow for supported existing cases and external-payment reference/review recording where its rules allow. A cancellation without a return case must not fabricate a customer return or mark a refund verified. Keep it as a payment exception for Owner review; any new refund-case behavior needs explicit design scope. PayMongo automatic refunds remain excluded. Customer no-receipt confirmation does not remove internal refund evidence or independent review.
8. Reuse current business permissions when specifying operator actions; explicitly map new gateway reconciliation operations to an appropriate reviewed gate before exposing them. No new role is assumed. Keep technical secret management separate from refund verification. Use existing sanitized diagnostics for technical failures and reviewed business views for payment exceptions.
9. Keep banking in approved scope but disabled per bank until official authentication, amount, test availability, stale-link/closure and retry behavior is verified. There is no basis to invent a standalone bank cancellation endpoint. Keep pass-on fees off under the supplied AGENTS policy until the documented permissions/disclosures are confirmed.

## Official-guide checklist for later work

The `paymongo-api` skill must reopen the relevant official page before implementing its operation and record date, endpoint/version, credential type, request/response, error handling, limits, events and uncertainty in `01-official-sources.md` or the assigned design evidence. Use current PayMongo documentation, not copied SDK examples from unrelated frameworks.

- [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout) and [channel concepts](https://docs.paymongo.com/docs/payment-channels-key-concepts): hosted URL, retry, session expiry, paid evidence and configured methods.
- [Direct Online Banking](https://docs.paymongo.com/docs/payment-acceptance-direct-online-banking): per-bank intent/method/attach/redirect flow, credential boundaries and limits; do not assume Checkout support.
- [Idempotency](https://docs.paymongo.com/reference/idempotent-requests): verify each mutation because the introduction and Notes differ about update support; preserve uncertainty beyond key expiry.
- [Testing](https://docs.paymongo.com/docs/payment-acceptance-testing): only documented test flows; use QR Ph test URL, not a real bank/wallet scan.
- [Webhook setup/signing](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management): endpoint-specific secret, raw-body verification, correct environment. Register nothing during preparation.

## Remaining external evidence, with safe outcomes

- Account owner privately supplies test keys and confirms available methods/banks. Missing keys or unavailable methods keep payment creation disabled; local fake-based implementation can be planned independently.
- Provider evidence must establish each banking route's safe lifecycle. If it cannot, that route remains unavailable rather than risking a duplicate charge or premature stock release.
- Authorized HTTPS test endpoint and correct test-mode webhook registration are needed only for external end-to-end testing. No real webhook registration or tunnel was performed here.
- Deployment must demonstrate a running worker that consumes the payments queue and a scheduler, and confirm its effective timeout/retry settings. Existing code proves the mechanism exists, not process health.
- Runtime Codex agent discovery remains to be demonstrated in the normal CLI session; documented roles/skills remain usable explicitly. No missing-home workaround or global configuration change is assumed.

These are account/environment/provider evidence requirements, not unanswered repository design choices. Do not report them as completed without evidence.
