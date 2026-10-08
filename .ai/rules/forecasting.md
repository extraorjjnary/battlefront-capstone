---
paths:
    - 'app/Actions/Forecasting/**'
---

# Forecasting

## Moving Average is an evaluation baseline only

EXT-43 evaluates the latest four consecutive completed quarters through the pure CalculateMovingAverage action and an in-memory adapter built from monthly history. Preserve zero-demand observations, exact two-decimal arithmetic and the target quarter determined by supplied history. This baseline is never offered for production generation or persisted as a new forecast.

## Monthly additive Holt–Winters contract supersedes previous calculator rules

The implemented contract is in CAPSTONE_CONTEXT Section 9 and completed EXT-40/42/43/45/46: source [T−36 months,T), monthly Sale.sale_date/OrderItem.quantity, trusted setup/import coverage before zero-fill. All-zero covered history is ready; other histories require ≥6 positive-sales months in each chronological 12-month block, else history_unsuitable. EXT-43 uses the documented two-cycle initialization, updated-level gamma recurrence, fixed 900-candidate grid with squared-error/lexicographic selection, explicit BCMath scale 12, per-month negative clamp and once-only half-up quarterly rounding. Production generation and new persistence use additive_holt_winters only; the completed MySQL/SQLite constraint migration preserves both legacy method identifiers, rows and upsert key. Monthly breakdown is generation-time context only. Linear Trend/Regression calculation is retired. Synthetic backtesting proves no real Battlefront accuracy.

## Holt-Winters fitting gamma ties and paired evaluation

EXT-43 consumes ProductForecastPreparationService::prepareMonthly output without querying, rebuilding buckets, checking runtime time, or changing EXT-42 readiness. Fitting months 25..36 cannot reuse their updated seasons within that same cycle: all gamma candidates have identical SSE, so ascending lexicographic ties select gamma=0.0; retain the approved full 900-candidate grid. Evaluate four consecutive held-out quarters with independent preceding 36-month fits, paired exclusions for all methods, trusted target actual coverage, and finalized quarterly MAE. The retained four-quarter moving average is used only through an in-memory evaluation adapter; synthetic results do not establish client accuracy.
