---
paths:
    - 'resources/js/app.js,resources/js/pages/**'
---

# Pages

## Exclude self-framed public pages from AppLayout

Guest-accessible Inertia pages that provide their own public header must return null from the default layout resolver in resources/js/app.js. AppLayout contains authenticated navigation whose AppHeader expects auth.user to exist.
