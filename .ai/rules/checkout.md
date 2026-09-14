---
paths:
    - 'config/battlefront.php,app/Http/Controllers/CheckoutController.php,resources/js/pages/Checkout/**'
---

# Checkout

## Configure wallet receiving details explicitly

GCash and Maya receiving account names/numbers come from battlefront configuration and must remain separate per wallet. Never infer them from branch contact data. Unconfirmed credentials use visibly labeled, non-payable demo placeholders until the client supplies confirmed values.
