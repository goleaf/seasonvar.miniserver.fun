---
name: seasonvar-ui
description: Use for the Russian Seasonvar catalog interface, Blade views/components, Tailwind layouts, responsive catalog/title pages, playback variant controls, Plyr/HLS UI, and visual QA. Trigger when editing `resources/views`, `resources/css`, `resources/js`, Blade components, view models that feed UI state, or user-facing text.
---

# Seasonvar UI

## Overview

Build a quiet, usable Russian catalog interface that exposes real catalog data and playback variants clearly. Avoid marketing copy, fake content, decorative clutter, and hidden data queries in views.

## First Steps

- Read `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/views.md`, neighboring Blade components, and the view model/page builder feeding the target view.
- Use the `tailwindcss-development` skill for Tailwind utility work.
- Keep visible UI text in Russian.
- Prefer existing components in `resources/views/components` before adding new markup.

## UI Rules

- Do not add in-app text explaining features, shortcuts, or how the UI was built.
- Do not use Blade `@php`/`@endphp`; prepare data in controllers, services, view models, or components.
- Do not run database queries from Blade views.
- Escape untrusted output with `{{ }}`.
- Keep fixed-format UI stable with explicit dimensions, grids, aspect ratios, or min/max constraints.
- For playback variants, make quality, format, translation/voice, subtitle state, and availability scannable without hiding alternatives.
- Use local assets/icons already installed through npm. Avoid CDN dependencies.

## Responsive Checks

- Check mobile, tablet, and desktop when layouts or text density change.
- Ensure text does not overlap or overflow controls.
- Keep catalog tools efficient for repeated scanning: filters, lists, title cards, variant selectors, and stats should be compact and predictable.

## Verification

- Run `npm run build` for frontend asset, Blade asset, Tailwind, Vite, or JS/CSS changes.
- Run focused feature/component tests for changed views when available.
- Use Playwright screenshots for visual regressions on substantial UI changes.
