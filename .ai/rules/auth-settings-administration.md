---
paths:
    - 'app/Models/User.php,config/fortify.php,routes/**,app/Providers/FortifyServiceProvider.php,resources/js/pages/{auth,settings,Administration/Customers}/**,tests/Feature/{Auth,Settings,Administration}/**'
---

# Auth Settings Administration

## Keep email verification optional and disabled

The former EXT-5 mandatory email-verification requirement is superseded. Do not implement MustVerifyEmail, enable Fortify email verification, register verification UI/routes, or apply verified middleware unless the developer explicitly re-enables the feature with a reliable queued mail provider. Keep users.email_verified_at for future re-enablement, and preserve auth plus role authorization boundaries.

## Require email OTP before web and mobile authentication

The developer-approved mandatory email OTP requirement supersedes the earlier disabled/optional verification decision and queued-provider condition. New customers and real administrators must verify with the shared hashed six-digit OTP (5-minute expiry, 5 attempts, 60-second resend cooldown) before login; Fortify registration must never sign in, and API registration/verification must never issue Sanctum tokens. Keep default link verification disabled, reject stale unverified sessions/tokens, and preserve credential-confirmed recovery and deferred guest shopping continuation. Only explicitly trusted demo seeders pre-verify accounts. Use configured synchronous SMTP; never inspect secrets or send real email during implementation/tests without separate authorization.

## Automatically sign in only customers verified in their original web registration session

The approved web registration refinement supersedes the separate-login step only for a new public customer who completes OTP verification in the original registration context with unchanged account/email/password. Consume server-only account/context/credential binding, sign in through the normal web guard without remember-me, rotate the session, and reuse Fortify LoginResponse plus guest cart/recommendation continuation. Registration itself remains unauthenticated; credential-recovery starts clear this eligibility. Existing-account recovery, administrators, and mobile verification continue to require their separate normal login; never issue mobile tokens from verification.

## Isolate registration rate limits from guest activity

Use the named registration limiter (5 attempts/minute/IP) for both Fortify web and API signup. Laravel's unprefixed numeric throttle keys are shared by guest domain/IP, so product activity or OTP requests can otherwise exhaust signup's smaller allowance. Keep OTP issuance/verification budgets and cooldowns independent; do not disable or flush security limits to solve a registration collision.
