---
paths:
    - 'app/Services/Notifications/**,app/Actions/{Inventory,Order}/**,app/Services/Order/**,resources/js/{components/NotificationBell.vue,composables/useNotificationNavigation.js}'
---

# Jscomponents

## Route workflow notifications to active responsible recipients

Supersedes blanket administrator broadcasts: use NotificationRecipients for active permission-qualified reviewers, requesters and handlers. Return submission alerts receiving Staff; receipt alerts independent reviewers. Cancellation-request approval suppresses its internal order.cancelled notice and combines positive refund approval into one Refund processing customer notice. Preserve approver-confirmed refunds/proof, legacy confirmation, after-commit publication, event/recipient deduplication, retries and customer-only Expo. Operational events reuse the existing pipeline and case destinations; never emit duplicate alerts from derived Tasks. Dropdown read-all reuses shared persisted state and invalidates stale polling through the existing Inertia mutation lifecycle.
