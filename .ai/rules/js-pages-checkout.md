---
paths:
    - 'app/Actions/{Checkout,Order}/**,app/Http/Requests/ValidateCheckoutRequest.php,app/Services/DeliveryQuotePresenter.php,resources/js/pages/Checkout/**'
---

# Js Pages Checkout

## Use EXT-88 shared checkout quotes and strict delivery placement

Shared checkout returns 12 server-calculated delivery quotes plus a zero-fee pickup quote; clients select one canonical destination and never calculate or submit trusted quote values. PlaceCustomerOrder must use executeWithDeliveryQuote for delivery, retaining EXT-87 atomic persistence and proof cleanup. Checkout calendar windows use one quote-generation date in config('app.timezone') and calendar-day offsets; they are provisional, subject to payment verification, and never persisted as shipment dates. Order detail reads saved fees/carrier/relative ETA only; EXT-89/90 own shipment workflow/tracking/notifications.
