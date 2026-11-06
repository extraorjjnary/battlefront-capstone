---
paths:
    - 'app/Providers/AppServiceProvider.php,app/Concerns/PasswordValidationRules.php,app/Http/Requests/Api/V1/RegisterRequest.php,app/Actions/Fortify/**,resources/js/pages/auth/Register.vue,tests/Feature/Auth/RegistrationTest.php'
---

# Auth

## Use the shared 12-character password policy in every environment

Customer web and mobile registration must share PasswordValidationRules and Password::defaults(). Enforce at least 12 total characters (including special characters), uppercase and lowercase letters, and a special character in all environments. Numbers are optional; do not use uncompromised() or show a breach-check warning. Preserve FormRequests, hashing, confirmation, email OTP, authentication, and rate limits.
