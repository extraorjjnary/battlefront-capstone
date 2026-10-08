---
paths:
    - 'app/Actions/{Cart,Checkout,Order,Inventory}/**,app/Services/{Cart,Order}/**,app/Http/Controllers/OrderController.php,resources/js/pages/Checkout/**'
---

# Pages Checkout

## Place orders with one guarded stock transaction

EXT-33 order placement locks the customer/cart and relevant catalog/inventory rows, snapshots current prices and quantities, creates the pending order/items, conditionally decrements stock, and deletes the cart in one retried database transaction. E-wallet proof stays private and is deleted when placement fails. Later status or payment verification changes must never deduct initial stock again.

## Require explicit selected cart items for checkout
EXT-91 supersedes whole-cart review and unconditional cart deletion: web/API preview and placement require explicit nonempty distinct cart_item_ids, with no fallback. Resolve every selected ID against the customer's cart; revalidate selected quantities, eligibility, prices and stock under placement locks. Quote only selected products, remove only purchased rows, and delete the cart only when empty. Selection is temporary UI intent, never a database flag.
