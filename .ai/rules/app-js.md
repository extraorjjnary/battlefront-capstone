---
paths:
  - 'app/**,database/**,resources/js/**'
---

# App Js

## Treat all monetary amounts as Philippine pesos
Battlefront Computer Trading operates in the Philippines. Monetary columns such as product prices store Philippine peso amounts (PHP) using DECIMAL(12,2); do not add per-record currency fields unless multi-currency support is explicitly approved. Customer and administrator interfaces should label/format these amounts consistently as PHP/₱.
