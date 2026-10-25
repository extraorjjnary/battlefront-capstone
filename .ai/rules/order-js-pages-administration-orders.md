---
paths:
    - 'app/Actions/Order/**,app/Services/Order/**,resources/js/pages/Administration/Orders/**'
---

# Order Js Pages Administration Orders

## Block handler changes while customer cancellation is under review

Pending cancellation requests block claim, assignment and reassignment for every administrator, including Manager/Owner. Reuse the cancellation hold's current locking read after locking Order; preserve any existing handler and do not write assignment audit entries on rejection. Hide handler controls and guard stale submit callbacks during the hold. Rejected cancellation resumes ordinary handling permissions; approved cancellation remains terminal. Cancellation review/refund authorization stays independent of order handler assignment.
