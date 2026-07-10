---
name: seasonvar-playwright-qa
description: "Use for browser-based QA of the Seasonvar Laravel catalog with Playwright or Playwright CLI: visual checks, accessibility snapshots, screenshots, console/network errors, responsive title/catalog pages, recommendation blocks, playback UI, feed/sitemap smoke checks, and regression plans after Blade/Tailwind/importer changes."
---

# Seasonvar Playwright QA

## Overview

Audit the real rendered Seasonvar interface before finalizing UI, recommendation, playback, feed, or SEO changes. Prefer Playwright CLI snapshots when the browser daemon works; fall back to a small Playwright Node script with managed Chromium when the CLI requires an unavailable Chrome channel.

## First Steps

- Read `docs/UI_STANDARDS.md`, `docs/frontend.md` when present, the changed Blade/view model files, and the route list from `php artisan route:list`.
- Check `command -v npx`; if missing, report that Playwright cannot run until Node/npm is installed.
- Start a local server with `php artisan serve --host=127.0.0.1 --port=<free-port>` unless the app is already reachable.
- Store screenshots and reports under `output/playwright/`; do not create new top-level artifact folders.

## Browser Workflow

- Try the project Playwright wrapper first:

```bash
bash "$HOME/.codex/skills/playwright/scripts/playwright_cli.sh" open http://127.0.0.1:8013
bash "$HOME/.codex/skills/playwright/scripts/playwright_cli.sh" snapshot
bash "$HOME/.codex/skills/playwright/scripts/playwright_cli.sh" screenshot
```

- If the CLI daemon fails because Chrome is unavailable, use Playwright with managed Chromium from an installed package/cache and record the fallback in the final answer.
- Capture desktop and mobile at minimum: `1440x1200` and `390x844`. Add tablet for layout-heavy changes.
- Collect: HTTP status, `h1`, panel headings, horizontal overflow, console errors, page errors, failed requests, and screenshots.

## Seasonvar Routes

Always include the routes relevant to the change:

- `/` for home/catalog density.
- `/titles` for listing, filters, search, and pagination.
- `/titles/{slug}` for title details, player, seasons, recommendations, FAQ, and SEO blocks.
- `/feed.xml`, `/sitemap.xml`, or sitemap child routes for feed/SEO changes.
- API routes only when JSON behavior changed.

## Recommendation QA

- On title pages, verify `Советуем посмотреть` is not an empty dead block.
- If precomputed recommendations exist, verify ranked titles, reason badges, and no duplicate current title.
- If precomputed recommendations do not exist, verify fallback recommendations are shown in a single coherent block or the empty state is intentionally useful.
- After importer/recommendation changes, compare Playwright output with database counts from Laravel Boost or read-only SQL.

## Checks

- Fail the QA if there is horizontal overflow, overlapping text, broken visible controls, console page errors, missing primary headings, inaccessible empty states, or failed local assets.
- Treat external media metadata request failures separately from local asset failures; document them and avoid causing heavy video downloads during QA.
- Run `npm run build` for Blade/Tailwind/Vite/JS/CSS changes and the narrowest relevant PHPUnit tests.
