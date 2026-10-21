---
name: battlefront-branding
description: Apply Battlefront Computer Trading's established visual identity to customer and admin interfaces. Use for branding-sensitive UI design, styling, layout, copy labels, logo placement, design tokens, and visual consistency; do not use for backend-only work with no user-facing output.
---

# Battlefront Branding

Preserve a single recognizable Battlefront identity across the application. Warm Battlefront Light is the primary theme, with optional Dark Mode. The supplied logo artwork defines the brand identity; the semantic tokens in [app.css](../../../resources/css/app.css) define the current interface palette. This skill documents those approved choices rather than authorizing a new visual identity.

## Source Assets

Before making a branding-sensitive decision, inspect the approved website assets rather than relying on memory:

- Light Mode wordmark: [battlefront-logo.webp](../../../docs/assets/battlefront-logo.webp) (`1200 x 303`, transparent canvas)
- Dark Mode wordmark: [battlefront-logo-dark.webp](../../../docs/assets/battlefront-logo-dark.webp) (`1200 x 303`, transparent canvas; approved white BATTLE lettering and subtitle)
- Compact symbol: [battlefront-symbol.webp](../../../docs/assets/battlefront-symbol.webp) (`512 x 512`, transparent canvas for both themes)

If an asset is moved, locate the same file in the repository and update these references. If the assets conflict with a written suggestion here, follow the assets and flag the discrepancy. Do not derive a new logo, mark, wordmark, or brand color without an explicit request.

## Logo Usage

- Use the full logo when the horizontal wordmark can remain legible: prominent customer-facing headers, authentication or landing screens, and other primary brand moments.
- Use the symbol for compact placements such as a collapsed sidebar, square brand tile, or small navigation header. At very small sizes, verify the internal `BCT` details remain readable; otherwise omit the mark instead of degrading it.
- Keep each image's original aspect ratio and complete visible artwork. Do not crop, stretch, rotate, recolor, redraw, typeset a substitute wordmark, remove details, or add shadows, outlines, gradients, or effects.
- Give the logo uncluttered surrounding space and strong contrast. Do not place competing text or controls over it.
- Reuse `AppLogo.vue` and `AppLogoIcon.vue`. The wordmark component selects the approved variant using the existing appearance preference; the symbol is shared across themes. Preserve their transparency without adding a background rectangle or using CSS filters to recolor them.
- Use accessible alt text appropriate to context. Use `Battlefront Computer Trading` when the logo conveys the site identity, and empty alt text when adjacent text already supplies the same identity.

## Two-Layer Color System

Keep logo identity colors separate from interface colors. The first layer preserves the approved artwork; the second provides the shared Warm Battlefront Light and Dark interface themes for customer and admin experiences.

### Brand Identity Layer

These anchors are visible in the source assets and describe the logo identity:

| Role       | Color     | Use                                                                                             |
| ---------- | --------- | ----------------------------------------------------------------------------------------------- |
| Logo red   | `#FF0000` | Existing logo artwork only; do not recolor the assets or automatically use this as a UI surface |
| Logo black | `#000000` | Existing logo artwork and intentional pure-black brand framing                                  |
| Logo white | `#FFFFFF` | Existing logo artwork and intentional maximum-contrast brand details                            |

The artwork also contains antialiasing, highlights, shadows, and red/gray gradients. These remain part of the raster artwork, not standalone design tokens. Do not sample incidental pixels into additional colors or recreate the gradients in routine UI.

### Application UI Layer

Use Warm Battlefront Light as the default. The main page background is warm off-white `#FAF7F4`; pure white `#FFFFFF` belongs to cards, popovers, navigation, and sidebar surfaces. Use subtle warm accents and red selectively for actions and emphasis.

These values match `:root` and `.dark` in `resources/css/app.css`. Reuse the semantic tokens instead of scattering hex values through components.

| Semantic token                                | Light (default) | Dark (optional) | Role                                           |
| --------------------------------------------- | --------------- | --------------- | ---------------------------------------------- |
| `--background`                                | `#FAF7F4`       | `#090B10`       | Main page and application background           |
| `--card`, `--popover`, `--sidebar-background` | `#FFFFFF`       | `#111318`       | Cards, dialogs, menus, and navigation surfaces |
| `--secondary`, `--accent`                     | `#F2EDE8`       | `#1B1E24`       | Secondary and selected surfaces                |
| `--muted`                                     | `#F0EBE6`       | `#1B1E24`       | Muted surface areas                            |
| `--warm-accent`                               | `#EBD6CC`       | `#2C2220`       | Subtle warm accents                            |
| `--foreground`                                | `#242120`       | `#F8FAFC`       | Headings, body copy, and labels                |
| `--muted-foreground`                          | `#6B625E`       | `#9CA3AF`       | Supporting text and metadata                   |
| `--primary`                                   | `#B91C1C`       | `#B91C1C`       | Solid primary actions                          |
| `--primary-foreground`                        | `#FFFFFF`       | `#F8FAFC`       | Text on primary actions                        |
| `--primary-ink`                               | `#B91C1C`       | `#F87171`       | Emphasized text, links, and icons              |
| `--brand-highlight`                           | `#EF1B1B`       | `#EF1B1B`       | Selective brand highlights                     |
| `--border`                                    | `#E2DAD4`       | `#2A2E36`       | Subtle dividers                                |
| `--input`                                     | `#94867D`       | `#687282`       | Visible input boundaries                       |
| `--ring`                                      | `#B91C1C`       | `#EF1B1B`       | Keyboard focus indicators                      |

### Appearance Behavior

- Default to Light when no valid explicit preference exists; preserve explicit guest and account Dark selections.
- Reuse the existing `useAppearance` composable and pre-paint initialization. Guest Light/Dark preferences and authenticated account preferences remain separate; do not introduce another theme store or operating-system override.
- Apply both themes through the shared semantic tokens and existing `.dark` selector. Do not create a separate administrator palette or force a theme on individual pages.

### Contrast and State Rules

- Use `bg-primary` with `text-primary-foreground` for filled actions. The bright `#EF1B1B` highlight is not the default filled-button surface for normal-sized text.
- Use `text-primary-ink` for red text and icons so their color follows the active theme. Keep supporting text on the matching semantic background and verify contrast when adding opacity or overlays.
- Keep borders restrained. Subtle `--border` dividers must not be the only visible boundary of an interactive control; use the existing input surface, `--input` boundary, and visible focus ring.
- Do not rely on red alone for validation errors, destructive actions, or status. Retain labels, icons, and the existing semantic state variants.

## Typography

- Use the project's existing `Instrument Sans` stack for interface text. No separate official brand typeface is established by these assets.
- The stylized lettering belongs to the logo artwork. Do not imitate or reconstruct it with a display font.
- Favor bold, compact headings and clear hierarchy to echo the strong technical character of the mark without turning ordinary UI copy into logo-like lettering.
- Use uppercase sparingly for short navigation labels, badges, table headers, or eyebrow text. Keep sentences, form labels, actions, and longer headings in readable sentence or title case according to existing component conventions.

## Visual Style

Aim for a clean, approachable computer-retail aesthetic: warm off-white page backgrounds, white surfaces, readable charcoal text, compact spacing, and restrained red accents. Keep the same hierarchy and geometry in optional Dark Mode. Favor clear product imagery, concise copy, subtle elevation, and purposeful borders over decorative effects. Let product information and customer tasks dominate.

Reuse established components and interaction patterns before creating variants. Keep corner radii, elevation, icon treatment, spacing rhythm, focus states, disabled states, and responsive behavior consistent with surrounding screens. Do not copy the logo's bevels, glow, speed lines, or gradients onto routine cards, buttons, tables, or form fields.

## Naming and Labels

- Use `Battlefront Computer Trading` as the formal business name and `Battlefront` as the compact product name when space is limited.
- Do not introduce variants such as `BattleFront`, `Battle Front`, or `BCT` in prose unless existing approved content requires them. The letters inside the symbol are artwork, not permission to rename the product in text.
- Prefer direct, specific, sentence-case action labels such as `Add to cart`, `Place order`, `Save changes`, and `Update stock`.
- Use the same noun for the same concept throughout customer and admin flows. Follow established domain language in the application and approved project context; do not invent marketing claims, operational promises, or new business terminology.
- Distinguish admin-only concepts with precise operational labels rather than a separate brand voice.

## Tailwind-Oriented Guidance

- This project uses Tailwind CSS v4 and CSS-first theme configuration. Preserve existing semantic utilities such as `bg-background`, `text-foreground`, `border-border`, and `text-muted-foreground` for general interface structure.
- Map the application UI layer to the existing semantic CSS variables instead of scattering hex values through Vue templates: background to `--background`, primary text to `--foreground`, surface to card/popover/sidebar surfaces, muted text to `--muted-foreground`, and border to `--border` where a subtle divider is appropriate.
- Use `bg-background` for the warm-neutral page shell, `bg-card` for white surfaces, `bg-secondary` for secondary areas, and `bg-warm-accent` sparingly. These utilities adapt through the existing theme tokens.
- Use `bg-primary text-primary-foreground` for filled actions, `text-primary-ink` for emphasized text/icons, and `ring-ring` for focus. Do not substitute `--brand-highlight` or the logo-only `#FF0000` for those semantic roles.
- Reuse the current CSS-first theme mappings before adding utilities; do not introduce a parallel palette that drifts from `resources/css/app.css`.
- Prefer semantic component variants over scattering repeated arbitrary color values through Vue templates. Reuse the project's class-merging and component patterns.
- Provide visible focus, hover, active, disabled, error, and loading states. Brand styling must not weaken keyboard navigation, readability, or contrast.
- Preserve responsive behavior, Light as the default, and optional Dark Mode through the existing appearance flow. Select the approved logo variant instead of recoloring the artwork or changing the interface palette to match it.

## Customer and Admin Consistency

Both experiences must share the same logo files, two-layer color system, typography, component vocabulary, icon style, and interaction-state meanings.

- Customer UI may give more space to the full logo, products, promotions, and primary shopping actions. Keep the journey approachable and content-led.
- Admin UI may be denser and more restrained. Prefer the compact symbol where navigation space is limited, and reserve red for selection, emphasis, and high-priority actions rather than large decorative areas.
- A component serving the same purpose in both areas should keep the same base appearance and behavior. Vary density or information hierarchy only when the audience's task requires it.
- Never create separate customer and admin palettes or competing visual identities. Apply the approved UI layer through shared semantic tokens and primitives while allowing density and information hierarchy to reflect each workflow.

## Decision Boundary

Using the approved Light/Dark tokens and logo variants is already authorized. When a new treatment would recolor or redraw the artwork beyond those variants, add an unapproved brand color, choose an official brand typeface, or materially depart from these source assets, ask for explicit direction. Present implementation-specific UI choices as applications of the existing identity, not as new brand standards.
