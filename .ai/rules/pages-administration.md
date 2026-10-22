---
paths:
    - 'app/{Models/User.php,Services/TeamAccessService.php,Http/Controllers/Administration/TeamController.php},database/**,resources/js/pages/Administration/Team.vue'
---

# Pages Administration

## Separate IT administration from business ownership

The existing admin@example.com account is IT, not the business owner. The fixed IT role can manage staff/manager accounts and read audit logs, but cannot access business operations, change role permissions, export audits, or alter Owner/IT accounts. Owner retains those controls. New IT administrators must change password and enroll in 2FA.

## Separate IT administration from business ownership

The existing admin@example.com account is IT, not the business owner. The fixed IT role can manage staff/manager accounts and read audit logs, but cannot access business operations, change role permissions, export audits, or alter Owner/IT accounts. Owner retains those controls. Two-factor authentication is currently disabled; signed-in active accounts with a role may use permitted features without enrollment or a forced password change.

## Current administrator sign-in gate

Supersedes the earlier requirement in this file that IT or other administrators must change passwords and enroll in 2FA before access. Two-factor authentication is disabled for now, and signed-in active accounts with an assigned administration role can immediately use only the permissions granted to that role.
