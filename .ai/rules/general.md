---
paths:
    - '**'
---

# General

## Linear issue inspection and update boundaries

For Linear MCP usage, treat the issue description—including its explicit scope, acceptance criteria, dependencies, and reopened-audit requirements—as the authoritative Linear source. Do not inspect, fetch, summarize, or rely on comments unless the developer explicitly asks; keep calls minimal and stop once the description is sufficient.
Do not change status or otherwise modify an issue (including comments, labels, relations, or priority) unless explicitly requested or clearly required by the current task with an unambiguous intended status. Never substitute a status when Review is unavailable without explicit approval, and preserve human review as the final gate before Done.

## Forecasting scope and manuscript boundary
The latest developer-approved forecasting revision and completed EXT-40/42/43/45/46 implementation supersede the earlier two-method and four-quarter production decisions. Production is product-level monthly additive Holt–Winters using 36 completed months and three monthly estimates summed to quarterly demand; administrators do not select models, parameters, history or coverage. EXT-44 retires obsolete paths while preserving legacy Forecast records. Preserve unrelated manuscript content/formatting; Figure 8's old raster label remains an explicit artwork follow-up.
