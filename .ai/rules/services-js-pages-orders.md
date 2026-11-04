---
paths:
  - 'app/Services/PayMongo/**,app/Services/CustomerOrderPresenter.php,resources/js/pages/Orders/**'
---

# Services Js Pages Orders

## Choose one hosted payment action from confirmed provider evidence
Developer decision 2026-10-30: show Continue payment only for an unfinished usable hosted payment with no unacknowledged confirmed failure/expiry; show Try payment again only after confirmed failure/expiry and safe order eligibility. Hide both during processing/uncertainty or after payment/cancellation. Keep retry's existing endpoint/tab behavior: revalidate provider state, reuse active sessions, and create replacements only after safe confirmed closure. QR expiry does not imply session expiry; use provider expiry evidence. The shared presenter owns the mutually exclusive action for web/API; polling must reconcile provider state without generating replacement payments.

## QR test simulator can resolve its Source without updating its Payment Intent
Verified on BF-000007 (2026-10-31 Manila): PayMongo QR Fail test left its Checkout Session active and Payment Intent awaiting_next_action with null last_payment_error and no payments, while the current code's Source was expired. For test mode only, derive the source identity from the provider-generated secure-authentication.paymongo.com test_url, bind code_id to the current next_action.code.id, and retrieve via authenticated GET /v1/sources/{id}. Validate resource ID, test mode, qrph type, amount and currency. Treat failed/expired/cancelled as negative attempt evidence only; never use Source status as proof of payment or restore stock from it. This deprecated Source endpoint is a test-simulator fallback, not a production payment API direction.

## Replace terminal QR test Sources only after verified session closure
The test simulator can leave an active session pointing at an expired Source; acknowledging failure and returning its URL repeats the expired-Source error. Observation must return a fresh-session requirement for the validated current test QR Source (failed/expired/cancelled), without provider writes. Explicit customer retry uses CloseHostedCheckout and creates a replacement only after confirmed closure; uncertain/processing/paid states block it. Preserve normal active-session reuse for other methods and ordinary QR expiry. Official closure contract reviewed 2026-10-31: https://docs.paymongo.com/docs/payment-channels-key-concepts.

## Do not infer payment-state conflicts from unchanged MySQL updates
MySQL reports zero affected rows when status, checkout URL and updated_at are unchanged, including repeated observations within one second. Verify allowed flow state under the existing order-then-flow row locks instead of treating an update count of zero as a race. Keep a frozen-time MySQL regression for repeated QR failure observations followed by customer retry; SQLite alone does not reproduce this defect.

## Observe unpaid hosted returns even when the session is still active
A hosted test return may leave its session active with an empty payments array and no intent error while the current QR Source is expired. Always pass unpaid hosted returns through ObserveHostedCheckout; session-level negative evidence is not a prerequisite. Observation must not acknowledge failure, create a replacement, or release stock. Customer expiry guidance must use confirmed recovery state while order/payment status remains pending.
