---
paths:
    - app/Http/Requests/Administration/SaveProductRequest.php
---

# Requests Administration

## Guard decimal syntax before monetary authorization comparisons

FormRequest authorization runs before validation. is_numeric accepts scientific notation that BCMath rejects; check plain decimal syntax before comparing submitted price, discount, or acquisition cost values. Invalid input must not throw a server error or bypass monetary permissions.
