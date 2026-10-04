---
paths:
  - 'app/Actions/Forecasting/**'
---

# Forecasting

## Moving-average baseline uses four supplied quarters
EXT-43 uses the latest four consecutive quarters as a full-year demand baseline, consuming EXT-42 History without querying or rebuilding history. Preserve every zero bucket: an explicitly aggregated no-sales entity forecasts 0.00, while an absent or empty series is missing_history; one to three buckets is insufficient_history. Coverage cannot be inferred from first-sale dates. Average quantity_sold with exact decimal arithmetic, returning two decimal places (integer quantities divided by four are exact); target the quarter beginning at the supplied history end, not the runtime clock.

## Linear trend fits all supplied quarters with a four-quarter minimum
EXT-44 fits quantity_sold over all supplied consecutive completed quarters with x=1..n and targets x=n+1 at History.end_exclusive; require four observations, including zero demand. Use shared PrepareQuarterlyForecastHistory for both forecasting actions, without queries or rebuilding buckets. Absent/empty history is missing_history; one to three observations is insufficient_history. Keep exact BCMath integer fractions until output: slope, intercept and signed raw projection use six decimals, forecast_quantity uses two, ties round away from zero. Clamp exact negative projections to 0.00 before rounding and retain raw_forecast_quantity plus was_clamped; never project from rounded coefficients.
