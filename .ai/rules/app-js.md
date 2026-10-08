---
paths:
    - "app/**,database/**,resources/js/**"
---

# App Js

## Treat all monetary amounts as Philippine pesos

Battlefront Computer Trading operates in the Philippines. Monetary columns such as product prices store Philippine peso amounts (PHP) using DECIMAL(12,2); do not add per-record currency fields unless multi-currency support is explicitly approved. Customer and administrator interfaces should label/format these amounts consistently as PHP/₱.

## Keep default delivery addresses as checkout-only prefills

Store at most one nullable default_delivery_address on the user. Use it only to pre-fill delivery checkout; checkout edits must not update the profile, placed orders retain the submitted delivery_address snapshot, and pickup must keep order delivery_address null.

## Limit default delivery addresses to customer profiles

Only customer profiles may display or persist default_delivery_address. Administrator profile responses must not expose it, and administrator profile updates must exclude it from validated data.

## Store appearance preferences per authenticated user

Persist light, dark, or system on users and share the current value with every Inertia response. Guest and logout state must resolve to system; never use global appearance localStorage or cookies that can leak a prior account's preference.
