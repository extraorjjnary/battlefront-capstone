---
paths:
  - 'app/Services/PayMongo/**,app/Services/Order/**,resources/js/pages/Administration/Orders/**'
---

# Pay Mongo Services Order Js Pages Administration Orders

## Keep PayMongo recovery independent from payment creation and order cancellation
Use ObserveHostedCheckout for provider observation without creating replacements; recover issued flows even when new gateway starts are disabled. Coordinate retry/closure through PaymentRecovery's order lock, authorize the handler before provider mutations, persist closure intent, and require every flow to be safely closed and unpaid before stock release. Present active flow plus history; financial anomalies remain visible across terminal orders and block retries/fulfillment. Recovery runs on the existing payments queue with due-time batching/backoff; QR expiry is not session closure.
