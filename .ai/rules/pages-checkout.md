---
paths:
    - 'app/Actions/{Cart,Order,Inventory}/**,app/Http/Controllers/OrderController.php,resources/js/pages/Checkout/**'
---

# Pages Checkout

## Place orders with one guarded stock transaction

EXT-33 order placement locks the customer/cart and relevant catalog/inventory rows, snapshots current prices and quantities, creates the pending order/items, conditionally decrements stock, and deletes the cart in one retried database transaction. E-wallet proof stays private and is deleted when placement fails. Later status or payment verification changes must never deduct initial stock again.
