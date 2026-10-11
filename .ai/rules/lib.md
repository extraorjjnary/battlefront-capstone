---
paths:
    - 'resources/js/pages/Administration/Forecasting.vue,resources/js/lib/forecastUnits.js'
---

# Lib

## Round forecast units only at the presentation boundary

Show nonnegative forecast quantities as nearest whole units with half-up display rounding in generated summaries, monthly estimates, saved review and chart labels/tooltips. Preserve decimal response values, chart datasets, predicted_demand persistence, calculation and evaluation precision; never use formatted quantities for stock planning arithmetic. Explain that displayed monthly estimates may not sum to the displayed quarter.
