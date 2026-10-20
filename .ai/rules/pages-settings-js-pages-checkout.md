---
paths:
  - 'app/Http/Requests/Settings/ProfileUpdateRequest.php,app/Support/PhilippineMobileNumber.php,resources/js/pages/settings/Profile.vue,resources/js/pages/Checkout/Index.vue'
---

# Pages Settings Js Pages Checkout

## Persist the customer mobile contact default
Keep one optional customer default contact number on users.default_delivery_contact_number. Accept Philippine 09XXXXXXXXX and +639XXXXXXXXX mobile formats with spaces/hyphens, normalize stored values to +639XXXXXXXXX, and prefill checkout; each order keeps its submitted contact snapshot. Omission preserves the profile value and blank clears it.
