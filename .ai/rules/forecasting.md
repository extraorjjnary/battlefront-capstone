---
paths:
  - 'app/Actions/Forecasting/**'
---

# Forecasting

## Moving-average baseline uses four supplied quarters
EXT-43 uses the latest four consecutive quarters as a full-year demand baseline, consuming EXT-42 History without querying or rebuilding history. Preserve every zero bucket: an explicitly aggregated no-sales entity forecasts 0.00, while an absent or empty series is missing_history; one to three buckets is insufficient_history. Coverage cannot be inferred from first-sale dates. Average quantity_sold with exact decimal arithmetic, returning two decimal places (integer quantities divided by four are exact); target the quarter beginning at the supplied history end, not the runtime clock.

## Linear trend fits all supplied quarters with a four-quarter minimum
EXT-44 fits quantity_sold over all supplied consecutive completed quarters with x=1..n and targets x=n+1 at History.end_exclusive; require four observations, including zero demand. Use shared PrepareQuarterlyForecastHistory for both forecasting actions, without queries or rebuilding buckets. Absent/empty history is missing_history; one to three observations is insufficient_history. Keep exact BCMath integer fractions until output: slope, intercept and signed raw projection use six decimals, forecast_quantity uses two, ties round away from zero. Clamp exact negative projections to 0.00 before rounding and retain raw_forecast_quantity plus was_clamped; never project from rounded coefficients.

## EXT-45 persists product forecasts only
Forecast persistence follows the manuscript product-only Forecast ERD plus the approved method field. Accept only completed product results from moving_average or linear_trend; category calculations remain valid but cannot be persisted without a separate ERD amendment. Store finalized demand, target quarter, and generated_at only. Reruns replace the row keyed by product_id, method, and forecast_quarter.

## Approved single-method forecasting migration supersedes earlier rules
The developer-authorized 2026-10-05 study revision supersedes the earlier two-method rules: production uses product-level trailing four-quarter moving_average only, with source [T-4 quarters,T) and target starting at current-quarter boundary T in app timezone. EXT-40 owns setup/import coverage metadata; EXT-42 gates forecasting aggregation before zero-filling; unknown/incomplete/gapped/stale coverage is unavailable and a shorter complete interval is insufficient. Never infer completeness from Sale dates, Product.created_at, or generated buckets. EXT-43's pure calculator remains accepted; EXT-44 retires trend after EXT-45/46 migration. New persistence accepts moving_average only; preserve product-focused schema, method column, legacy trend rows and product+method+quarter replacement. No routine method/history selectors or coverage checkbox. During migration, current two-method code is legacy state, not approved scope.

## Monthly additive Holt–Winters contract supersedes previous calculator rules
Latest approved contract is in CAPSTONE_CONTEXT Section 9 and revised EXT-40/42/43/45/46: source [T−36 months,T), monthly Sale.sale_date/OrderItem.quantity, trusted setup/import coverage before zero-fill. All-zero covered history is ready; other histories require ≥6 positive-sales months in each chronological 12-month block, else history_unsuitable. EXT-43 uses the documented two-cycle initialization, updated-level gamma recurrence, fixed 900-candidate grid with squared-error/lexicographic selection, explicit BCMath scale 12, per-month negative clamp and once-only half-up quarterly rounding. New method is additive_holt_winters; EXT-45 must extend MySQL/SQLite constraints while preserving legacy rows/columns/upsert key; monthly breakdown is generation-time context only. Old calculators remain until replacement is implemented/tested/human-accepted; EXT-44 then retires unused trend and obsolete production paths, retaining moving average only as a used evaluation baseline. Synthetic backtesting proves no real Battlefront accuracy.
