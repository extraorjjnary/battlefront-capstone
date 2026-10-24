---
paths:
    - 'app/Actions/Inventory/HandleCustomerReturn.php,app/Services/CustomerReturnRequestPresenter.php,app/Http/Controllers/Administration/CustomerReturnController.php,resources/js/components/{OrderReturnRequests,RefundAmountFields}.vue,resources/js/pages/Administration/CustomerReturns.vue'
---

# Js Pages Administration

## Retire customer-facing resolution summaries

The developer-approved refinement removes resolution_summary inputs and the summary requirement for manual outcomes and partial refunds. Preserve outcome selection, required internal review notes, monetary bounds, inspection rules and fulfillment confirmation. Hide legacy summaries from customer web/API presentation (summary remains null for compatibility); retain stored history and separate public_feedback.
