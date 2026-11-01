---
paths:
  - 'app/Services/PcBuilder/**,app/Services/Order/OrderPlacementService.php,app/Actions/Cart/**,resources/js/pages/PcBuilder/**,database/seeders/PcBuilder*'
---

# Pages Pc Builder Seeders

## Allow partial PC purchases without treating them as verified complete builds
Developer approved 2026-11-01: reuse existing products only; Simple has guided core/cooling slots plus optional extras, Advanced exposes all 17 types. Missing components and unknown evidence allow nonempty parts purchases through cart and checkout; confirmed incompatibilities block both. Keep complete separate from can_purchase, revalidate live evidence/stock at purchase, and retain whole-group selection and immutable snapshots. Exact CPU/board BIOS evidence is required for a pass; curated draft backfills never approve facts or create products.

## Stage compatibility-first selection until existing catalog facts are verified
The developer's subsequent 2026-11-01 compatibility-first revision supersedes permitting unknown selected-part compatibility once strict mode is enabled. Strict candidates and purchases allow missing future components but reject unknown evidence, unresolved warnings and conflicts among selected parts; evaluate quantities and replacements on the server. Keep admin evidence approval, visible Remove actions and explicit replacement-removal confirmation. config/pc-builder.php keeps strict_selection_enabled false until a complete sourced in-stock template passes readiness; catalog scanning must not approve draft facts or invent products. Existing drafts and cart groups remain available for correction.
