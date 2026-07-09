# UI Standards

Last updated: 2026-07-09

## Theme

The catalog UI is light-only.

Do not use:

- `bg-black`
- `bg-zinc-900`, `bg-zinc-950`
- `bg-slate-900`, `bg-gray-900`, `bg-neutral-900`
- `text-white` for normal UI blocks
- `bg-white/[...]`, `border-white/...`, dark translucent panels
- dark page backgrounds

Use instead:

- Page background: `bg-slate-50`
- Panels: `bg-white`, `border-slate-200`, `shadow-slate-200/60`
- Muted blocks: `bg-slate-50`
- Primary accent: `emerald-50`, `emerald-100`, `emerald-700`
- Text: `text-slate-700`, `text-slate-600`, `text-slate-500`

## Shared components

Use shared Blade components before writing repeated markup:

- `x-ui.panel` for all bordered sections and side blocks.
- `x-ui.taxonomy-chip` for every taxonomy link or pill.
- `x-ui.section-title` for section heading blocks when a full panel header is not needed.
- `x-title-poster` for every catalog poster image or placeholder.
- `x-title-card` for title grid cards.
- `x-title-list-row` for responsive list rows with poster thumbnail, title metadata, and counters.
- `x-stat` for dashboard counters.

## Readability rules

- Every major block needs a short visible title.
- Relation links must look clickable and stay readable on mobile.
- Long descriptions use normal line height and slate text, not small low-contrast text.
- Dense metadata should use label/value layout or chips, not raw comma strings.
- Player placeholder must remain light even when no media is connected.

## Layout rules

- Use `gap` utilities for spacing between siblings.
- Prefer `grid` for page layout and responsive card lists.
- Avoid duplicating panel header classes inside pages; use `x-ui.panel`.
- Keep mobile layout single-column before desktop grids.
- Main catalog lists should show a compact poster thumbnail beside each title when a poster is available.
- Use `minmax(0, 1fr)` in multi-column page grids to avoid horizontal overflow.
- Tablet layouts should avoid forcing three dense columns before `xl`.
