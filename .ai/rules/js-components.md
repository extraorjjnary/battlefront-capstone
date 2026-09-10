---
paths:
    - 'app/Http/Controllers/BranchController.php,resources/js/pages/Branches/**,resources/js/components/BranchMap.vue'
---

# Js Components

## Keep branch status copy customer-facing

Keep is_operational available for application logic and tests, but do not expose internal labels such as “Reference location only” or “operations are not confirmed” in the customer branch UI. Emphasize Sagay with its open-hours badge, present other locations neutrally, and advise customers to confirm availability before visiting.
