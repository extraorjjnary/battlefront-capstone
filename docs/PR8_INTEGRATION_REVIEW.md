# PR #8 integration review

Review and follow-up implementation date: 2026-11-05 (Asia/Shanghai). This is a local, uncommitted integration candidate. Business policies were confirmed during planning; commit, push, merge and deployment remain separate gates.

## Repository evidence and isolation

- Repository: https://github.com/extraorjjnary/battlefront-capstone
- PR: https://github.com/extraorjjnary/battlefront-capstone/pull/8
- Fetched base: `f6c6adeef3542400a96dc3335b4707e7bc45dad9` (`main`).
- Fetched head: `225b1e962f3c65d9769f70c77e6c03dc4fe6d749` (`marku-mark:algolia-catalog-search`).
- GitHub reported 11 incoming commits, 194 changed files, 13,235 additions and 1,356 deletions relative to the merge base. This is substantially broader than catalog search.
- Integration branch: `integration/pr-8-latest-main`.
- Worktree: `storage/app/pr8-integration` under the original checkout. Run Git and verification commands from this worktree.
- The original checkout, `main`, and the contributor's remote branch were not edited. No commit, push, provider operation, webhook registration, persistent application-database migration, seeding, or production operation was performed.
- The worktree has separate Composer/Node dependencies and an ignored synthetic testing environment. Original `.env` credentials were not copied or printed.

The read-only assessment used the fetched refs, incoming diffs and `git merge-tree --write-tree` before applying the merge. Source edits began only after reporting the actual conflicts and cross-module behavioral changes.

## Exact conflicts and resolutions

| Conflicting file | Actual conflict | Resolution |
| --- | --- | --- |
| `.ai/rules/index.md` | Both branches changed the mapping table; the PR also reformatted it. | Retain the incoming mapping table, including corrected wildcard patterns and new catalog/payment/shipment/builder rules, plus all mappings unique to current main. In particular, preserve the role-retirement, Expo-retirement and latest chatbot guidance. No rule file is discarded. |
| `composer.json` | Main reformatted the manifest while the PR added dependencies. A mechanical merge duplicated much of the manifest. | Start with main's valid manifest and add only `algolia/algoliasearch-client-php: ~4.51.0` and `laravel/scout: ~11.9.0`. Preserve main's scripts, including `npm run check -- --no-fmt`. Retain the incoming lock file; Composer confirms it matches. |
| `resources/js/pages/Administration/DamageReports.vue` | Main imports `formatRelativeTime`; the PR adds export controls and imports the superseded `formatAgeDays`. | Keep `formatRelativeTime` and add `OperationalExports`. Preserve timestamp-based ages and all export controls. Do not restore the obsolete helper. |
| `resources/js/pages/Administration/SupplierReturns.vue` | Main's relative-time import overlaps the incoming export import. | Keep both imports, main's timestamp-based ages, incoming supplier-product filtering, and incoming export controls. |

No other files had Git conflict blocks. Cleanly merging files were also reviewed; a clean textual merge is not evidence of compatible business behavior. No contributor feature was removed to obtain a clean merge. The obsolete age-helper import was excluded because main replaced that API and its behavior; all new export and supplier-selection functionality remains.

## Incoming functional changes

1. **Catalog search:** Scout 11.9.0 and Algolia PHP client 4.51.0, disabled-by-default configuration, server-only environment placeholders, intent/category aliases, conservative typo handling, unit normalization, literal quoted phrases, mixed-category primary-device ordering, and curated synonyms. Algolia supplies complete sets of at most 1,000 IDs; live SQL applies availability, filters, sorting and pagination. Provider failures, incomplete/oversized responses and invalid IDs fall back to all-term SQL. A successful empty response remains empty. Indexing reloads current product IDs after commit, handles deletion/restoration and category renames, and has an explicit reconciliation command. Search documents exclude acquisition costs and other private commerce data. Chatbot/recommendation matching remains database-backed.
2. **PC Builder:** Simple/Advanced component groups across 17 types, partial-parts purchases, additional compatibility findings, exact CPU/board/BIOS evidence, server-evaluated candidate selection and replacement previews, explicit removal confirmation, stale-response protection, stock aggregation across repeated SKUs, cart/checkout revalidation and immutable component snapshots. Strict selected-part evidence enforcement defaults off pending catalog readiness. Guided specification controls retain arbitrary/uncommon values and advanced JSON. The expanded catalog mapping seeds drafts for existing products, not approved compatibility facts or new products.
3. **PayMongo:** Persistent recovery outcomes, attention flags and due times; provider observation separate from payment creation; mutually exclusive Continue/Try again actions; current and historical flow presentation; authenticated recovery when new starts are disabled; order-level locks, retry serialization and persistent cancellation intent; all-flow closure checks before stock restoration; stale-attempt webhook handling; financial-anomaly escalation; bounded due-work dispatch and backoff; authorized administrator reconciliation; test-only QR Source observation. Web/mobile reuse shared services. The gateway remains test-mode-only, and cancellation remains separate from refunds.
4. **Delivery and shipments:** Nationwide demo v8 config supplies regional provisional transit windows and automatic AwaitingPreparation shipments for new configured destinations. Historical saved quotes and manual-arrangement paths remain. Atomic LBC handoff combines package measurements, actual carrier expense, receipt confirmation and tracking; independent package/cost editing stays removed. The incoming cost-equality gate was replaced under the developer's explicit follow-up approval: actual expense can differ while customer charges remain immutable. A read-only shipment directory adds filters, stable pagination, streamed CSV and PDF/print capped at 500 rows, with existing order/export permissions.
5. **Inventory and returns:** Additive supplier-product links, optional local/testing demo assignments to six existing products, filtering and server validation for assigned suppliers, and manual selection for suppliers without assignments. Existing quarantine, return approval, refund and history workflows remain. Review repositories accept explicit page numbers for exports; damage reports gain an ID pagination tie-breaker.
6. **Administration and presentation:** CSV/PDF/print exports for stock requests, damage reports, supplier returns, received customer returns and customer requests; report visibility/scope gates and spreadsheet formula escaping; payment recovery UI and filters; shipment navigation; minor FAQ layout/long-text improvements and navigation icon changes. These FAQ edits do not replace chatbot routing, context or product linking.
7. **Supporting changes:** Additional regression tests, two forward/data-preserving migrations, builder data/configuration, restored project-specific skills and Graphify references, and new shared-rule mappings. The PR changes no forecasting algorithm; main's newer monthly historical-fixture refinement remains intact.

## Preservation of recent main work

Comparison against current main confirms the integration leaves its core role model/access service, chatbot actions/services, notification services and notification retry job unchanged. The application provider retains both development workers, with only the payments timeout increased from 30 to 70 seconds. In particular:

- IT Administrator stays retired; no IT account/role is restored by incoming application changes.
- Expo send/receipt jobs, adapter, device registration service and endpoints stay removed. Historical migrations/tables and shared notification history stay retained.
- Customer/admin web notifications, mobile history/count/read endpoints, recipient permissions, read state and after-commit notification publication remain.
- Chatbot product links, short-term follow-up context, shortcuts and regional/temporal refinements remain.
- Main's `default` and `payments` development processes remain. The payments timeout now accommodates the existing 60-second job deadline. The incoming PR also changes payment job behavior, lock requirements and scheduling load.
- Order placement retains its existing locked transaction, authoritative prices, selected-cart ownership, stock deduction, failure rollback and snapshot boundaries. Cancellation restores quantities only after eligible transition and confirmed safe gateway closure. Shipment status is not payment verification; COD collection/remittance and pickup handover remain separate workflows.

Shared workflows stay in existing Actions/Services, and complex catalog/inventory/order queries stay in repositories. No parallel payment, notification, mobile-admin or inventory architecture was introduced. Follow-up implementation moved reconciliation locking, eligibility, due-time writes, dispatch and audit into `RequestPaymentReconciliation`; its controller now delegates and returns the existing redirect. Unrelated container lookups in the incoming services were not expanded into a broader refactor.

## Additional integration fixes and regression coverage

- `CustomerOrderPresenter` now separates access to an existing hosted payment from permission to create/retry a payment. The PR accidentally hid an existing usable checkout link when new starts were disabled, unlike main. A failing regression reproduced that difference before the fix. Four state cases now ensure disabling new starts retains only usable continuation, and web/API tests verify the existing link remains available without replacement flow creation or payment-state changes. Failed/expired/uncertain states still hide unavailable actions; retry remains disabled.
- `CommerceRecoveryUpgradeTest` builds a separate in-memory SQLite database using all current-main migrations, including those newer than the two incoming files, then applies the two pending migrations. It verifies unchanged customer, order, inventory, item and paid-flow values, safe recovery defaults, an empty supplier pivot and continued IT-role retirement. The memory connection is disconnected afterward; no persistent database was created or dropped locally. A second, explicitly gated case performs the same upgrade in a generated disposable MySQL database only in approved CI. Baseline flow setup avoids populating Eloquent's process-wide guardable-column cache from the intentionally older schema, so this upgrade test does not contaminate later recovery tests.
- Existing stock-visibility and recommendation-reset tests now use the real Networking, Laptops & Desktops and Peripherals category names. The old randomly named category fixtures no longer represented Router/Laptop intent queries. Assertions and workflows were preserved; no search, inventory or recommendation behavior was weakened to pass.
- The existing MySQL CI job now enables and includes the incoming PayMongo concurrency suite alongside COD. No local MySQL lifecycle commands were executed.

## Confirmed business decisions

The developer explicitly selected the following policies during planning and then requested implementation. They supersede conflicting earlier guidance. Durable follow-up rules were recorded through Boost's `RecordRule` tool executed against the integration worktree, leaving the original checkout unchanged.

| Decision | Current main | Initially incoming | Confirmed resolution |
| --- | --- | --- | --- |
| Builder purchase boundary | Requires a complete validated build. | Allows nonempty partial parts; unknown findings remain visible, and strict selection defaults off. | Keep partial purchases, disclose missing/unknown evidence, block confirmed incompatibility, and keep strict mode staged until readiness. |
| Nationwide fulfillment | Regional fallback pricing can require a manual shipment arrangement and has no automatic ETA. | v8 config supplies demo transit rules and creates new shipments nationwide. | Keep demonstration fulfillment and provisional ETAs for new orders; preserve all historical quotes/manual arrangements. |
| Carrier cost | Saved quote metadata explicitly records `fixed_at_placement_battlefront_absorbs_variance`. | Handoff required actual cost equal to the saved fee and blocked historical differences. | Record true carrier expense separately. Battlefront absorbs differences; customer totals and confirmed evidence remain unchanged. |
| Payment operations | Existing overdue/unknown-operation recovery. | Active-flow observation, persistent closure intent and attention-based holds. | Keep and validate the broader recovery features without weakening payment, closure or reservation gates. |

Other selected boundaries: indexing failure containment with operator recovery rather than an outbox; PDF/print limited to 500 records with unrestricted streamed CSV; generated test-database lifecycle operations only inside disposable CI; publication through a new integration PR, leaving PR #8 and its contributor branch untouched.

No business-rule decision remains unresolved. Human diff review, external/manual evidence and the CI gates below remain outstanding.

## Implemented follow-up fixes

- **Actual carrier expense:** the nested handoff request accepts `actual_carrier_cost` as a nonnegative amount with at most two decimal places. An unconfirmed receipt requires an explicit entered amount, reference and confirmation. Previously confirmed amounts/references cannot be replaced, and historical differences no longer block otherwise eligible handoff. Declared value remains derived from saved merchandise charges. Order totals, payment status, stock and historical commercial snapshots are unchanged.
- **Thin reconciliation controller:** `RequestPaymentReconciliation` owns the existing transaction and dispatch/audit behavior. Existing permissions, handler ownership, cooldown, queue and response contracts remain. Regression coverage includes a surrounding rollback producing no queued payment work or audit change.
- **Indexing containment:** product/category hooks share `QueueCatalogSearchSync`, which guards the actual dispatch inside an after-commit callback. Queue or logging failure cannot invalidate a committed save; rollbacks enqueue nothing. Logs contain public product IDs/counts, not names, provider errors or credentials. Existing current-state jobs and retries remain.
- **Bounded exports:** general CSV now queries one existing repository page at a time during response streaming. General PDF/print reject more than 500 records with the `export` validation error and visible CSV guidance. Stable ID tie-breakers were added where missing. Existing scopes, filters, formula escaping, monetary strings and private headers remain.
- **Worker/CI preparation:** the payments listener now allows 70 seconds around the 60-second reconciliation job, below the existing 90-second reservation. CI installs PCNTL/POSIX and runs COD, PayMongo and upgrade cases against generated disposable MySQL databases. The Linux worker test uses the actual database queue and shared database cache, checks real job-timeout termination, retained reserved stock, uniqueness, and subsequent paid recovery without duplicate notifications or sales. It advances only its isolated fixture lease/reservation timestamps to exercise expiry recovery without another two-minute wait.

## Review findings and remaining risks

- **Payment concurrency evidence is still required before merge.** CI now includes the PayMongo concurrency/worker test and the MySQL forward-upgrade case alongside COD. Local tests leave their environment switch disabled. Their generated database creation/deletion is approved only for disposable CI, which cannot run until an approved commit and push.
- **The ordinary full local pipeline hit its 512 MB test-process limit.** It terminated in file MIME detection before producing final test totals. Export and image-upload tests pass together in isolation (61 tests, 223 assertions). A full diagnostic run completed all cases using an ignored copy of the same PHPUnit configuration with a 1 GB limit; committed application/test configuration remains unchanged. Its wrapper returned 1 because Artisan duplicated `--configuration`, which triggers a PHPUnit warning despite passing assertions. A focused direct Pest run with the same configuration returned 0 without warnings. The normal Linux CI run must still pass its committed configuration before merge.
- **Indexing recovery remains operator-led by explicit choice.** Enqueue/logging failures are contained, but no outbox or guaranteed automatic crash recovery was added. Failed jobs and sanitized diagnostics require operator attention and full reconciliation.
- **Index freshness affects discovery.** Live SQL prevents stale IDs from exposing inactive/out-of-stock products, but a healthy provider returning stale zero hits cannot trigger the SQL fallback. Failed/delayed indexing therefore can omit newly matching products until reconciliation. Worker health, failed-job review and reconciliation are release requirements.
- **Worker timing must pass Linux CI.** Reconciliation/indexing jobs declare 60-second timeouts, uniqueness/order locks use 120 seconds, and the database queue defaults to `retry_after=90`. The payments listener is now 70 seconds. Native Windows does not establish PCNTL termination safety; the new real-worker case remains an explicit merge gate. Timeout/interrupted/uncertain provider work must retain stock and recover through existing persisted state.
- **External/manual evidence remains separate.** No Algolia service, PayMongo sandbox transaction/webhook, actual courier, mobile device journey, browser layout audit or persistent MySQL upgrade was exercised. The automatic suite uses fake outbound requests and in-memory SQLite unless explicitly gated otherwise.
- **Graphify tooling unavailable.** No graph existed in either checkout. The required `graphify update .` attempt failed because the executable is not installed/discoverable. No dependency was installed solely to bypass that limitation.

## Validation record

Runtime: Windows, PHP 8.4.5 and Node 24.13.0. The repository CI targets Node 22 and Linux; those environments were not executed locally. Ignored logs are in this worktree's `storage/logs/pr8-*.log`.

| Check | Result |
| --- | --- |
| `composer validate --no-check-publish` | Pass; only the existing exact Dompdf-version warning. Lock file matches the merged manifest. |
| `npm run build` | Pass. The remote Bunny font request timed out even with approved network access; reusing the original checkout's unchanged font cache allowed the normal build to complete without source/config changes. |
| `npm run test:frontend` after follow-up changes | 472 passed, 18 skipped, zero failures. The skipped cases require an explicitly configured browser/server. An initial fresh-worktree run started before Wayfinder generated routes; its missing-route-file failure disappeared after generation and the full rerun passed. |
| Focused catalog + payment tests | 363 passed, 1,963 assertions before the additional continuation regressions. |
| Final upgrade + payment-retry regression run | 90 passed, 505 assertions. Includes the forward-upgrade test and six continuation-state/web/API cases. |
| Final catalog availability + recommendation workflow run | 56 passed, 1,257 assertions. |
| `php vendor/bin/pint --dirty --format agent` | Pass. No unrelated PHP formatting changes. |
| Original resolved-candidate `composer ci:check` | Passed before this follow-up: 5,280 passed, 41 skipped; 247,555 assertions across 5,321 cases. |
| `composer ci:check` after follow-up implementation | Frontend lint, Pint and PHPStan passed; the PHP suite terminated at the configured 512 MB memory limit in `FinfoMimeTypeDetector.php:76`, before final totals. This pipeline did not pass. |
| Follow-up full PHP suite, diagnostic 1 GB process limit | 5,298 passed, 43 skipped, zero test failures; 247,631 assertions across 5,341 cases in 473 seconds. All cases completed. Command exit was 1 due to Artisan's duplicate `--configuration` warning, reproduced separately; a focused direct Pest invocation returned 0. No test selection, assertion, fake, database guard or committed configuration was changed. |
| Git conflicts and whitespace | Zero unmerged index entries; working/index whitespace checks passed. |
| MySQL concurrency, real Linux worker, and MySQL upgrade | Not run locally. The approved disposable CI job is prepared to run all three after commit/push approval. No persistent MySQL upgrade has been performed. |

The complete diagnostic run includes catalog/fallback, builder compatibility/cart/checkout, payment/webhook/recovery/cancellation, inventory/order reservation, shipments, supplier returns, notifications, chatbot, mobile API and migration tests. Explicitly gated MySQL/disabled-feature cases remain skipped; skipped cases are not counted as passing. This diagnostic evidence does not establish a successful ordinary `composer ci:check` run for the follow-up candidate.

The first full run overlapped the initially missing Vite build: it reported 5,197 passed, 76 failed and 41 skipped. Seventy-three failures were in early web rendering while the manifest was absent, and three were the now-corrected category fixtures. The final clean run above supersedes that failed run.

## Migration and deployment requirements

Two migrations are newly incoming relative to main:

- `2026_10_30_162208_add_recovery_tracking_to_paymongo_flows_and_orders.php`: nullable recovery/closure metadata, failure count default zero, attention flag default false and due/attention indexes. It rewrites no payment/order history. `down()` deliberately refuses to drop financial evidence.
- `2026_10_31_152936_create_product_supplier_table.php`: empty supplier/product pivot with a composite primary key and restrictive foreign keys. It has no operational relationship backfill; rollback refuses to discard populated relationships.

Apply pending migrations forward through the separately approved deployment process before new code/workers use these columns/tables. Retain backups and verify an upgrade against representative MySQL data. Existing dates preceding main's newest migrations do not make these two migrations already applied: Laravel checks its migration ledger. No migrate/fresh/reset/rollback/seed command was run against the original database.

Deployment also requires the locked Composer install, frontend install/build, environment-specific configuration caching and worker restart/drain planning. Retain main's IT-role preflight and legacy-Expo drain requirements if not already deployed. Existing IT assignments must be reviewed under the retirement migration; do not auto-reassign them. Do not seed demo suppliers or draft profiles into operational data as part of deployment.

Algolia stays disabled with `SCOUT_DRIVER=null` until separately configured. For an approved activation, use a unique environment index prefix and private server credentials; synchronize index settings using `scout:sync-index-settings`, then populate/reconcile public documents and curated synonyms using `catalog:reconcile-search`. These are external writes and were not executed here. `SyncCatalogSearch` uses the application's default queue connection/name rather than a dedicated Scout queue. A general/default worker must continuously process it; monitor three-attempt failures and reconcile stale documents. Changing `scout.queue` alone does not reroute this custom job. [Laravel's Scout queue guidance](https://laravel.com/framework/docs/13.x/scout#queueing) also notes provider-side indexing is asynchronous.

If enqueue failures or index staleness occur after activation, switch customer search to SQL with `SCOUT_DRIVER=null` through the approved configuration/restart process. Keep notification and payment processing serviced. Investigate failed jobs and sanitized catalog diagnostics; then, in a controlled maintenance window, enable the intended index configuration, synchronize settings and run full catalog reconciliation. Confirm provider-side tasks have completed and representative searches include recently changed products before reopening indexed search. This is an operator procedure, not automatic recovery or an outbox guarantee.

Keep a separately serviced `payments` queue and the minute scheduler/inbox recovery. New reconciliation is unique per flow and coordinates via the shared order lock; dispatching and handling processes must share an atomic-lock-capable cache. Paid/in-flight flows still need recovery credentials even when new starts are disabled. Keep the general worker for exception-only `RetryOrderNotifications`; normal database/web history remains synchronous. Do not restore an Expo queue or push worker.

PayMongo attempt/session closure must remain distinct from the merchant cutoff and from refunds. The reviewed [PayMongo lifecycle guidance](https://docs.paymongo.com/docs/payment-channels-key-concepts) supports separate attempt/session handling; [webhook management guidance](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management) requires private server keys. These pages were read only. No provider/dashboard/tunnel activity was performed.

## Recommendation and handoff

**Needs further changes/validation before merge.** Business decisions are confirmed, and the architectural, enqueue-failure and export-scale findings have been implemented. Review the resulting diff and local verification evidence, then obtain explicit commit/push approval. The exact published head must pass standard CI under its committed memory configuration, MySQL concurrency/upgrade and Linux worker checks before a separate merge approval. Passing diagnostic SQLite assertions cannot substitute for these gates. If Linux reproduces the memory exhaustion, address test-process resource usage or explicitly review its budget before merging.

The merge remains uncommitted in the integration worktree. Review the complete candidate using `git diff --cached origin/main` and any remaining working changes using `git diff`. The original checkout remains clean. After approval, publish the integration branch to a new PR referencing #8; do not overwrite or close the groupmate's PR automatically. Commit/push/merge and external operations require separate approval. Do not push the current branch tip assuming it already contains an integration commit.
