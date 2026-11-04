---
paths:
    - 'app/Services/Notifications/**,app/Listeners/PersistOrderNotifications.php,app/Jobs/**,app/Providers/AppServiceProvider.php,routes/api.php'
---

# Providers

## Preserve shared history after retiring Expo push

Developer-approved Expo push retirement supersedes earlier Expo/device-registration rules. Preserve synchronous after-commit database history for customer web/mobile and administrator web, recipient permissions, event/recipient deduplication and read state. RetryOrderNotifications uses database/default with existing retry protection; normal history needs no worker. Retain historical migrations/push tables and all notification history. Keep PayMongo payments workers/jobs/schedules unchanged; drain legacy notifications-queue work under the old release before deployment.
