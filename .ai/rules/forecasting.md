---
paths:
  - 'app/Actions/Forecasting/**'
---

# Forecasting

## Moving-average baseline uses four supplied quarters
EXT-43 uses the latest four consecutive quarters as a full-year demand baseline, consuming EXT-42 History without querying or rebuilding history. Preserve every zero bucket: an explicitly aggregated no-sales entity forecasts 0.00, while an absent or empty series is missing_history; one to three buckets is insufficient_history. Coverage cannot be inferred from first-sale dates. Average quantity_sold with exact decimal arithmetic, returning two decimal places (integer quantities divided by four are exact); target the quarter beginning at the supplied history end, not the runtime clock.
