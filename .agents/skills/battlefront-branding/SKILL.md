---
name: battlefront-branding
description: Apply Battlefront Computer Trading's established visual identity to customer and admin interfaces. Use for branding-sensitive UI design, styling, layout, copy labels, logo placement, design tokens, and visual consistency; do not use for backend-only work with no user-facing output.
---

# Battlefront Branding

Preserve a single recognizable Battlefront identity across the application. The supplied raster artwork is the visual source of truth; this skill interprets it but does not authorize changing it.

## Source Assets

Before making a branding-sensitive decision, inspect both files rather than relying on memory:

- Full horizontal logo: [battlefront-logo.png](../../../docs/assets/battlefront-logo.png) (`2172 x 724`, transparent canvas)
- Compact symbol: [battlefront-symbol.png](../../../docs/assets/battlefront-symbol.png) (`1254 x 1254`, opaque black background)

If an asset is moved, locate the same file in the repository and update these references. If the assets conflict with a written suggestion here, follow the assets and flag the discrepancy. Do not derive a new logo, mark, wordmark, or brand color without an explicit request.

## Logo Usage

- Use the full logo when the horizontal wordmark can remain legible: prominent customer-facing headers, authentication or landing screens, and other primary brand moments.
- Use the symbol for compact placements such as a collapsed sidebar, square brand tile, or small navigation header. At very small sizes, verify the internal `BCT` details remain readable; otherwise omit the mark instead of degrading it.
- Keep each image's original aspect ratio and complete visible artwork. Do not crop, stretch, rotate, recolor, redraw, typeset a substitute wordmark, remove details, or add shadows, outlines, gradients, or effects.
- Give the logo uncluttered surrounding space and strong contrast. Do not place competing text or controls over it.
- Account for the symbol's baked-in black square background. Place it intentionally on a dark-compatible surface; do not simulate transparency or remove its background unless the user explicitly requests an asset edit.
- Use accessible alt text appropriate to context. Use `Battlefront Computer Trading` when the logo conveys the site identity, and empty alt text when adjacent text already supplies the same identity.

## Two-Layer Color System

Keep logo identity colors separate from interface colors. The first layer preserves the source artwork; the second provides a practical dark UI system for both customer and admin experiences.

### Brand Identity Layer

These anchors are visible in the source assets and describe the logo identity:

| Role       | Color     | Use                                                                                             |
| ---------- | --------- | ----------------------------------------------------------------------------------------------- |
| Logo red   | `#FF0000` | Existing logo artwork only; do not recolor the assets or automatically use this as a UI surface |
| Logo black | `#000000` | Existing logo artwork and intentional pure-black brand framing                                  |
| Logo white | `#FFFFFF` | Existing logo artwork and intentional maximum-contrast brand details                            |

The artwork also contains antialiasing, highlights, shadows, and red/gray gradients. These remain part of the raster artwork, not standalone design tokens. Do not sample incidental pixels into additional colors or recreate the gradients in routine UI.

### Application UI Layer

Use this approved dark palette for branded application interfaces:

| Role          | Color     | Use                                                                                   |
| ------------- | --------- | ------------------------------------------------------------------------------------- |
| Primary red   | `#EF1B1B` | Brand accents, active indicators, icons, links where contrast passes, and focus rings |
| Dark red      | `#B91C1C` | Solid primary-action surfaces and darker red interaction states                       |
| Background    | `#090B10` | Main page and application shell background                                            |
| Surface       | `#111318` | Cards, panels, dialogs, and navigation surfaces                                       |
| Surface light | `#1B1E24` | Raised, selected, or secondary surfaces                                               |
| Primary text  | `#F8FAFC` | Headings, body copy, labels, and high-emphasis content                                |
| Muted text    | `#9CA3AF` | Supporting text, metadata, placeholders, and secondary content                        |
| Border        | `#2A2E36` | Subtle dividers and nonessential surface separation                                   |

Apply the UI layer through semantic roles rather than using raw values by convenience. Use red selectively so hierarchy remains clear, and never recolor the logo to match the softer UI reds. If a light theme is retained or introduced, keep its existing semantic neutral tokens until a separate light palette is explicitly approved; the logo identity layer remains unchanged.

### Contrast and State Rules

- Primary text has strong contrast on the background and surface colors. Muted text is also suitable for readable secondary copy on those dark surfaces.
- `#F8FAFC` on primary red `#EF1B1B` has approximately `4.14:1` contrast and does not meet WCAG AA for normal-sized text. Do not use that pairing for ordinary filled buttons or small labels.
- Use dark red `#B91C1C` with primary text `#F8FAFC` for filled actions; the pairing has approximately `6.18:1` contrast.
- Primary red against the background has approximately `4.54:1` contrast and is suitable for visible accents and focus indicators.
- Border `#2A2E36` is intentionally subtle and has insufficient contrast to be the only visible boundary of an interactive control. Inputs and controls need additional surface contrast, labels, and clearly visible focus states.
- Do not rely on red alone to distinguish brand actions, validation errors, destructive actions, or status. Pair meaning with labels, icons, placement, or other established cues.

## Typography

- Use the project's existing `Instrument Sans` stack for interface text. No separate official brand typeface is established by these assets.
- The stylized lettering belongs to the logo artwork. Do not imitate or reconstruct it with a display font.
- Favor bold, compact headings and clear hierarchy to echo the strong technical character of the mark without turning ordinary UI copy into logo-like lettering.
- Use uppercase sparingly for short navigation labels, badges, table headers, or eyebrow text. Keep sentences, form labels, actions, and longer headings in readable sentence or title case according to existing component conventions.

## Visual Style

Aim for a confident computer-retail aesthetic: crisp geometry, strong contrast, structured spacing, and restrained red accents. Prefer clean surfaces and purposeful borders over decorative effects. Let product information and task hierarchy dominate; reserve the high-energy logo treatment for brand moments.

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
- Map solid primary actions to dark red with primary text as the foreground. Use primary red for accent and ring roles. Do not map the logo-only `#FF0000` anchor into general component tokens.
- When dedicated utilities are genuinely useful, define narrowly named Tailwind v4 theme variables such as `--color-brand-primary`, `--color-brand-primary-dark`, `--color-brand-surface`, and `--color-brand-surface-light` from the approved UI layer. Keep component behavior semantic rather than coupling every component to brand-specific utilities.
- Prefer semantic component variants over scattering repeated arbitrary color values through Vue templates. Reuse the project's class-merging and component patterns.
- Provide visible focus, hover, active, disabled, error, and loading states. Brand styling must not weaken keyboard navigation, readability, or contrast.
- Follow the project's responsive and dark-mode conventions. The logo identity remains red/black/white in every mode; do not derive an unapproved light UI palette from the logo artwork.

## Customer and Admin Consistency

Both experiences must share the same logo files, two-layer color system, typography, component vocabulary, icon style, and interaction-state meanings.

- Customer UI may give more space to the full logo, products, promotions, and primary shopping actions. Keep the journey approachable and content-led.
- Admin UI may be denser and more restrained. Prefer the compact symbol where navigation space is limited, and reserve red for selection, emphasis, and high-priority actions rather than large decorative areas.
- A component serving the same purpose in both areas should keep the same base appearance and behavior. Vary density or information hierarchy only when the audience's task requires it.
- Never create separate customer and admin palettes or competing visual identities. Apply the approved UI layer through shared semantic tokens and primitives while allowing density and information hierarchy to reflect each workflow.

## Decision Boundary

When a requested treatment would recolor or redraw the logo, add a color beyond the approved two-layer system, choose an official brand typeface, or materially depart from these source assets, stop and ask for explicit direction. Present implementation-specific UI choices as applications of the existing identity, not as new brand standards.
