# Disposable Laravel Cloud demonstration restoration

This is an operator-run workflow for a separate **capstone-demo** environment and dedicated disposable MySQL cluster. It never restores over the existing production database. Keep APP_ENV=production, destructive-command protection, existing roles, and mandatory email OTP. There is no reset endpoint or scheduled reset.

The repository implements **baseline capture and verification**, not database import, Cloud provisioning, payment closure, or deployment automation. Perform those operations only after reviewing this runbook and approving the concrete resource identifiers. A manifest is evidence of inspected state, not proof that Cloud or PayMongo is isolated.

## 1. Confirm the dashboard before enabling restoration

Record the following in the private operator change record, without passwords or keys:

- Production resource identifiers (denylist), demo application/environment/cluster/database identifiers, replacement identifiers, and the approved release's full commit SHA. Demo credentials must have no production access.
- Actual MySQL version, collation, timezone, snapshot availability, retention, restore permissions, and secure SQL export/import connectivity. Confirm that native restore creates a new cluster; do not assume an in-place restore or downloadable SQL snapshot exists.
- Every App/Worker background process, managed queue, scheduler toggle, automatic deployment, and deploy command. No reset or automatic seed command belongs in deployment configuration.
- Separate public and private object buckets; verify Cloud's named public/private disks and the existing private-to-local alias. Neither disk may point at production. Archive credentials must not be available to the application.
- Effective database queue/session/cache connections. The validator intentionally supports only standard database-backed stores in this release. External queues or caches require a separately reviewed isolation workflow.
- Demo-only domain, application key, host-only session cookie, test-only PayMongo credentials, endpoint and signing secret, and working Gmail SMTP. Keep secrets in Cloud configuration, never the runbook, shell history, manifests, logs, or source.
- Access to stop/restart the environment and switch resource attachments, the permitted restore tool, temporary endpoint controls, and an identified operator who can perform rollback.

Replicating an environment can copy variables and share resources. Inspect before its first deployment. Cloud restart runs deployment commands. The application cannot prove resource-account ownership from credentials or environment names.

References: [environments](https://laravel.com/cloud/docs/environments), [MySQL](https://laravel.com/cloud/docs/resources/databases/laravel-mysql), [object storage](https://laravel.com/cloud/docs/resources/object-storage).

## 2. Author and archive a clean baseline

Use a verified pre-demonstration source. If one does not exist, create an **empty separate database**, apply ordinary migrations, and follow existing catalog import, original stock provisioning, and hosted demo provisioning. Review the exact target, pinned private input hashes and strong privately supplied demo password before applying those existing commands. Never run reset-dev or migrate:fresh against the existing database; never delete protected history or disable its triggers.

The candidate must have:

- Exactly 654 imported products with all images and the original retailer-demo-v1 quantities/reorder levels. Exactly 654 initialization movements, with 650 in-stock, three low-stock, and one out-of-stock products.
- Exactly the 34 provisioned, active, administratively preverified demo identities and their role grants. Preserve existing provisioned password hashes when using a clean backup. If an additional real Owner exists, stop and review a revised identity contract; do not delete it to satisfy validation.
- Five branches, at least nine managed FAQ records, reference data, supplier links, draft builder data, and all audited hosted synthetic orders/items/sales/shipments and coverage. Exact contents, not merely counts, are fingerprinted.
- No temporary customer, ordinary/gateway order, operational return/adjustment, sessions, remember tokens, OTP/reset challenges, access tokens, jobs/failed jobs/batches, cache/locks, notifications, guest activity, or saved customer builds. These schemas remain present.

A contaminated database is not a baseline. The command never cleans it. Source stock audits and coverage must be valid; do not fabricate them to make inspection pass.

Turn off both temporary provisioning gates and gateway starts after provisioning. PayMongo must remain in test mode; the validator rejects live mode or enabled gateway starts. Retain demo disclosure and the existing exact database/host/connection binding, plus hosted forecasting enablement. A replacement host/database must be deliberately rebound to those existing gates before validation.

Stop all other writers (including workers and schedules) before export. Use a restricted operator execution context with no background processes or customer ingress for the validation command. Do not start the application normally just to inspect a stopped database.

Export the one application database with a compatible MySQL client using:

- single-transaction and quick export for InnoDB, triggers included, and schema/constraints/indexes/migration rows retained;
- no server accounts, CREATE DATABASE, USE, or DROP statements; no all-databases export;
- no-tablespaces, set-gtid-purged=OFF and skip-add-drop-table where supported; Cloud recommends skip-extended-insert for smaller instances;
- a private credential file or approved database client, TLS and narrowly scoped database privileges. Verify TRIGGER/SHOW permissions and supported DEFINER handling rather than dropping protections.

Inspect the export header and target-selection statements before import. Do not stream an unreviewed export directly into a running database. The validator hashes a supplied SQL file but does not parse it or certify its contents: a successful restore-and-verify rehearsal is mandatory.

Copy all baseline public/private objects into an immutable operator archive, retaining relative keys and byte content. Keep the SQL, manifest and archive **outside both application disks**, encrypted at rest with restricted access. Archive the compatible release and secret version privately; never rotate the application key casually during a restore.

From the quiesced candidate's isolated operator context:

```shell
php artisan battlefront:demo-baseline --capture=/operator/baseline.json --dump=/operator/baseline.sql --revision=FULL_40_CHARACTER_COMMIT_SHA --confirm-demo --quiesced --no-interaction
```

Replace paths and SHA with actual values. Capture refuses an existing manifest; it does not overwrite a previously approved baseline. It issues database reads only, streams hashes of all objects on public/local disks, and writes only the new local manifest.

The manifest binds the SQL checksum, release, table counts/content hashes, table definitions/history triggers, object hashes, timezones and audited coverage. It contains no raw account credentials or row contents, but paths and hashes remain private metadata. Store an independently recorded SHA-256 of the manifest in the operator approval record; hashes do not authenticate a manifest an attacker can replace.

## 3. Rehearse before approving the baseline

Restore the export into a new empty disposable database using the approved external tool, and copy baseline objects into fresh demo-only writable buckets. Never import into the source or a running database. Reject any import error, unsupported trigger/constraint, or unexpected schema instead of stripping protections.

Use the **same MySQL version/compatibility and release**, and an operator context bound to the replacement. Do not run migrations or seeders after import to make a mismatched archive pass.

```shell
php artisan battlefront:demo-baseline --verify=/operator/baseline.json --dump=/operator/baseline.sql --revision=FULL_40_CHARACTER_COMMIT_SHA --confirm-demo --quiesced --no-interaction
```

Verify against the original trusted manifest; do not recapture the replacement as a way to pass. A MySQL schema fingerprint requires visible history-protection triggers. AUTO_INCREMENT counters are excluded from schema comparison; record contents/IDs are still compared. SQLite automated tests are not a MySQL import rehearsal.

Then, on this sacrificial copy:

1. Register a consenting Gmail address, receive/verify OTP, checkout, complete a test order through the existing payment/fulfillment workflow, and adjust stock. Confirm sales appear only on completion.
2. Restore again. Verify baseline hashes, no temporary identity or transaction history, original stock, and unchanged synthetic coverage.
3. Register the same email again. Old OTP contexts, reset links, sessions, remember cookies and mobile tokens must not authenticate.
4. Deliver an old test event and delayed callback while the new cycle has reused numeric IDs. Verify no new payment/order/stock/sale changes. Unknown provider references must remain unmatched. Do not replay an old queued job into the new database.
5. Rehearse rollback, and repeat the full demonstration/restore cycle twice.

Discard smoke-test copies and restore once more before the real demonstration. Gmail messages, provider records and previously exported files are external effects; restoration cannot erase them. Confirm SMTP allowance/deliverability separately.

Coverage is calendar-bound. At a month rollover forecasting readiness may become unavailable even though restoration is correct. Prepare and review a new versioned baseline through the existing hosted history workflow; never extend coverage by changing dates or falsifying the runtime clock.

## 4. Reset an active demonstration cycle

1. Review/approve the demo target identifiers and rollback resources. Restrict registration, checkout and all customer/admin ingress; disable new gateway starts. Suspend auto-deployments.
2. Keep payment recovery credentials and recovery workers available while reconciling every issued test flow. Archive settled flows. Confirm authenticated closure for unpaid flows. Pending/processing/unknown operations, financial anomalies or unsupported closure **block the reset**. QR expiry, a return redirect, the 24-hour merchant cutoff and a disabled start flag are not closure evidence.
3. Once reconciled, disable the retired demo webhook endpoint and stop the entire demo environment. Verify all replicas, payments/default workers, scheduler, custom processes and operator commands have ended. queue:restart or scale-to-zero alone is not a stop barrier. Keep old queued and failed work attached to the retired database; never replay it into the replacement.
4. Preserve the stopped current database and its buckets, a verified backup, current release/configuration bindings, webhook state and a private cycle identifier. This is the rollback set.
5. Restore the trusted baseline to a **different empty database** and fresh bucket copies. If dashboard-verified native snapshot restore is used, select the approved baseline snapshot and retain the original cluster. SQL plus manifest remains the portable archive. Confirm snapshots contain only the dedicated demo cluster.
6. Verify the replacement with the command above while ingress/workers/scheduler remain off. Apply the demo resource bindings deliberately; rebuild configuration through the normal deployment process. Keep provisioning gates disabled. Do not restore any authentication/cache/queue state from the retired cycle.
7. Configure/review the demo webhook endpoint and signing secret. Never reuse a production endpoint. The actual Laravel receiver is /api/paymongo/webhook; the legacy webhook.php connectivity receiver does not process payments and must not be configured.
8. Start fresh default/payments consumers against the replacement, enable the scheduler and test webhook, verify process health, then reopen ingress. Preserve current timeout/reservation/lock settings. Ensure old deploys cannot reconnect to the new resources.
9. Retain the rollback set until acceptance, then remove only explicitly reviewed retired demo resources according to the agreed retention window.

PayMongo events are account-wide: changing the signing secret does not prove an event belongs to this cycle. Safety depends on **no restored provider flows**, fresh provider references/UUID operation keys, and existing provider-ID matching. Never match order metadata, BF references, or numeric IDs as payment authority. Disabling an endpoint does not replay missed events on re-enable; reconcile first.

References: [webhooks](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [payment lifecycle](https://docs.paymongo.com/docs/payment-channels-key-concepts).

## 5. Rollback and costs

On any validation/import/deployment failure, keep ingress closed. Stop the replacement's processes. Reattach the retained database, matching buckets and compatible configuration/release. Reconcile any provider changes since the rollback checkpoint before restoring webhook/worker access. Never blindly retry a payment creation or merge records between cycles. After new activity begins, rollback would discard that activity and requires another operator review.

Budget for demo compute, a dedicated MySQL cluster, temporary replacement/rollback capacity, snapshot storage, archive and bucket copies, and any worker/cache resources. Stopping app compute does not remove attached-resource charges. Minute-by-minute payment schedules and database queue polling can prevent scale-to-zero savings. Verify current regional rates and spending alerts in Cloud; no fixed monthly estimate is implied.

Cloud commands have runtime/ephemeral-disk limits. Keep archives durable outside application disks and use an approved external client for long imports. Database snapshots do not restore object storage, provider state, mail or externally downloaded exports. No production resource creation, live payment, webhook registration or deployment was performed by adding this workflow.
