---
name: battlefront-frontend-design
description: Design, implement, or review distinctive Battlefront Vue/Inertia interfaces, including pages, layouts, reusable components, hierarchy, spacing, motion, and interaction. Use for frontend design work; do not use for backend-only changes or redefining the brand itself.
---

# Battlefront Frontend Design

Create task-focused interfaces that feel specific to a modern computer-hardware retailer while preserving Battlefront's approved identity.

## Required Context

- Read and apply [battlefront-branding](../battlefront-branding/SKILL.md) before making visual decisions about logos, colors, typography, or naming. Do not reinterpret or extend the brand.
- Read [CAPSTONE_CONTEXT.md](../../../docs/CAPSTONE_CONTEXT.md) when project scope, actors, architecture, or web/mobile responsibilities affect the interface.
- Inspect the relevant existing pages, layouts, components, design tokens, and interaction patterns before proposing changes.

## Design Approach

Identify the interface's user, primary task, and most important content. Build the hierarchy around that task, then choose one strong visual idea that reinforces it.

Make the computer-retail domain visible through useful content and structure: product imagery, specifications, pricing, availability, comparisons, order state, or operational controls as appropriate. Favor crisp geometry, deliberate spacing, strong product presentation, and restrained red emphasis on a dark professional foundation.

Before implementation, write a short design plan covering hierarchy, reused components, the primary visual idea, key states, and responsive behavior. Challenge the plan: if its structure and decoration could be transferred unchanged to an unrelated SaaS product, revise it around Battlefront's actual retail or administrative task.

## UI Component Strategy

Use this priority order:

1. Reuse an existing Battlefront component if one already fits.
2. Use an existing shadcn-vue component for common UI primitives.
3. Use raw Reka UI only when shadcn-vue does not reasonably cover the needed behavior.
4. Create a custom component when the UI is genuinely Battlefront-specific.

Prefer shadcn-vue for common components such as:

- Button
- Input
- Select
- Checkbox
- Radio Group
- Dialog
- Alert Dialog
- Dropdown Menu
- Tabs
- Sheet
- Tooltip
- Popover
- Table
- Pagination
- Form-related controls

Do not use shadcn-vue automatically for every visual element.

Simple layout elements such as page sections, product cards, navigation layouts, hero areas, statistics layouts, and branded promotional sections may be implemented directly with Vue and Tailwind when a shadcn primitive adds no useful behavior.

When adding a new shadcn-vue component:

- Check whether it already exists in `resources/js/components/`.
- Add only the component needed for the task.
- Customize it according to the `battlefront-branding` skill.
- Do not introduce default shadcn styling that conflicts with Battlefront's visual identity.

## Implementation Constraints

- Use the existing Laravel, Inertia.js, Vue 3, and Tailwind CSS stack and established project conventions.
- Write Vue with the Composition API in plain JavaScript. Prefer `<script setup>` without `lang="ts"`.
- Do not add another component or design library without developer approval.
- Keep motion purposeful: use it to clarify state, continuity, or feedback, and respect reduced-motion preferences.
- Preserve accessible structure, keyboard operation, visible focus, readable contrast, and practical touch targets.

## Restraint Check

Avoid generic SaaS compositions, decorative metric cards, oversized generic heroes, gratuitous gradients or glass effects, repeated rounded containers, excessive shadow or glow, random accent colors, and animation or ornament without a UX purpose. Prefer usability, clear hierarchy, and one coherent idea over layered visual effects.

## Final Review

Review the result against the design plan and the branding skill. Check that it is recognizably appropriate for Battlefront, responsive across relevant breakpoints, accessible in its important states, consistent with nearby interfaces, and free of decoration that does not improve comprehension or action.
