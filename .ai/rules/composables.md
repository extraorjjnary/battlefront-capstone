---
paths:
    - 'resources/js/composables/useNotificationPolling*'
---

# Composables

## Poll notification summaries without requesting the current page

Web notification polling uses the existing Inertia useHttp client and Wayfinder session-only summary routes, updating only notificationSummary via replaceProp. Avoid usePoll/router.reload on arbitrary pages: eager checkout/reporting/forecasting work would rerun. Use one 30-second interval, pause hidden tabs, prevent overlapping requests, and invalidate/cancel responses on navigation, identity change and disposal; stop on 401/403. Keep server output recipient/audience scoped, private/no-store and limited to unread count plus five recent entries.

## Check server identity before replacing notification props

Summary JSON includes meta.user_id and meta.audience for the authenticated web session. Verify both against the requesting tab before applying data; cookies can change in another tab without updating local auth props. On mismatch clear the old summary and stop polling. Keep generation/identity guards inside replaceProp's callback too, so a queued update cannot cross a later account change.
