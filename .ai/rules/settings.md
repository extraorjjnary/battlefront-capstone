---
paths:
    - 'routes/settings.php,app/Http/Controllers/Settings/**,resources/js/pages/settings/**'
---

# Settings

## Do not expose customer self-deletion

Customer account settings must not expose or implement hard account deletion. Preserve users and their historical order/sales relationships; do not add account deactivation unless it is separately approved.

## Keep existing account emails immutable without weakening registration

Web and mobile profile updates share ProfileUpdateRequest and UpdateProfile. Permit omitted email or the exact stored value for legacy mobile clients; reject changes with an email validation error and never update email_verified_at. Keep required/valid/unique email rules in ProfileValidationRules for customer registration and preserve administrator creation validation. Password changes remain in Security settings.
