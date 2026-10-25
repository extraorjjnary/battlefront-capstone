---
paths:
    - 'app/Actions/Inventory/HandleCustomerReturn*.php,app/Services/CustomerReturn*.php'
    - 'app/Actions/Inventory/HandleCustomerReturn.php,app/Services/CustomerReturnRequestPresenter.php'
---

# Inventory Services

## Separate customer requests from physical return receipt

Customer requests reserve purchased quantities but never quarantine stock or create refunds. Allow one open request per order item until receipt/review completes; preserve receiving employee as customer_returns.requester_id for independent approval. Submission, withdrawal and both receipt paths serialize on Order before transactional snapshot reads, then OrderItem/request; conversion excludes its own reservation. Customer refund completion requires latest verified payment reviews covering a positive approved amount, never paid_amount or approval alone.

## Keep customer resolution separate from stock handling and payment

New accepted returns require an explicit positive bounded refund or a documented zero-money manual resolution. Acceptance never fulfills a repair/replacement; confirm actual fulfillment using returns.dispatch, with original-item handback releasing quarantine once. Preserve unknown legacy outcomes and derive refunds only from independently verified payments. Restock requires confirmed fit-for-sale findings; reported faults, damage, Other and unclassified cases also require accessible inspection evidence.

## Restrict refund payment recording to its approver

Only the original refund approver with returns.approve and returns.record_payment may make and record new refund payments. Another eligible Manager/Owner must independently verify; preserve original recorders on legacy payments. Default approval to purchased unit price times returned units, exclude shipping, and require a public explanation for partial monetary refunds. Payment forms/tasks use approved minus recorded nonvoided payments, not the balance still awaiting verification.

## Prepare receipt photos once across transactional retries

Both receipt entry points converge on HandleCustomerReturn: prepare validated photos once, then atomically receive, attach evidence, link the originating request, quarantine stock and audit under existing Order-first locks. Retry without writing duplicate files and discard prepared files on final failure. New receipt Tasks belong to eligible Staff; Manager/Owner reviews begin after receipt and existing historical receiver identities remain unchanged.

## Confirm new refunds through one approving Manager or Owner

Supersedes independent verification for NEW refund payments only: the original approver, with returns.approve and returns.record_payment, confirms actual payment. Store the authoritative verified review with confirmation_method=approver atomically with payment and proof; new noncash payments require one customer-visible image, cash requires actual handover confirmation without proof. A UUID submission_key prevents duplicate processing. Preserve legacy independent reviews and pending/disputed payments without retroactive confirmation. Customer completion derives from latest confirmed/verified payment totals, never approval alone; present these totals as confirmed.

## Keep internal resolution records when removing the public summary input

For new return approvals without a supplied summary, persist the required internal decision reason as the resolution summary. This preserves the existing database constraint requiring documentation for nonmonetary resolutions. Keep all resolution summaries hidden from customer output, including old rows; no database constraint removal or historical backfill is needed.
