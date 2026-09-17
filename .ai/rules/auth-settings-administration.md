---
paths:
    - 'app/Models/User.php,config/fortify.php,routes/**,app/Providers/FortifyServiceProvider.php,resources/js/pages/{auth,settings,Administration/Customers}/**,tests/Feature/{Auth,Settings,Administration}/**'
---

# Auth Settings Administration

## Keep email verification optional and disabled

The former EXT-5 mandatory email-verification requirement is superseded. Do not implement MustVerifyEmail, enable Fortify email verification, register verification UI/routes, or apply verified middleware unless the developer explicitly re-enables the feature with a reliable queued mail provider. Keep users.email_verified_at for future re-enablement, and preserve auth plus role authorization boundaries.
