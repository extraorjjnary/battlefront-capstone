---
paths:
    - app/Providers/FortifyServiceProvider.php
---

# App Providers

## Resume cart and wishlist intents independently after authentication

Keep guest cart and wishlist selections in separate 15-minute server session intents with separate replay-cache namespaces. Fortify LoginResponse must execute both continuations without short-circuiting; when both resume, use the wishlist redirect. OTP customer sign-in reuses this response. Never retain a consumed selection for a later account.
