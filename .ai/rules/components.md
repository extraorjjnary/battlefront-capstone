---
paths:
    - 'database/seeders/**,app/Http/Controllers/BranchController.php,resources/js/components/BranchMap.vue'
---

# Components

## Map only verified branch coordinates

Use the verified stored latitude/longitude values for Sagay, Escalante, and San Carlos, Bacolod, Guihuilngan and omit any branch without a complete valid coordinate pair from the map; do not infer or geocode missing positions.
