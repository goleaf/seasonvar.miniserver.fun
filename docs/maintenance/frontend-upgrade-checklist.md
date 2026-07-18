# Checklist frontend dependency upgrade

Применяется к Livewire browser bridge, Tailwind, Flux, Vite, npm libraries, icons/fonts и player modules. Flux/Flux Pro не устанавливается без подтверждённой package/license need; Volt запрещён.

- [ ] Current/proposed direct and transitive versions, engines and licenses recorded.
- [ ] Official installed-version upgrade guidance read.
- [ ] Node/npm range, lock format and post-install scripts reviewed.
- [ ] Vite entries, aliases, dynamic imports, base paths, source maps and manifest reviewed.
- [ ] Tailwind CSS-first imports, `@source`, theme, dynamic classes and plugins reviewed.
- [ ] Responsive breakpoints, long labels, safe areas, zoom, reduced motion and print reviewed.
- [ ] Livewire lifecycle, events, hooks, navigation cleanup, history and locale reviewed.
- [ ] Flux/custom modal, sheet, dropdown, form, table and focus behavior reviewed.
- [ ] Player HLS/Plyr lifecycle, captions, quality, PiP/fullscreen and cleanup reviewed.
- [ ] Accessibility semantics, keyboard, focus, labels, announcements and non-color meaning reviewed.
- [ ] Browser capability detection and documented support matrix reviewed.
- [ ] Payment/OAuth redirect pages and protected/admin/private UI boundaries reviewed.
- [ ] Service-worker registration/cache/version/private allowlist reviewed; absence recorded honestly.
- [ ] Public bundle/chunk/CSS/font size and duplicate libraries reviewed.
- [ ] No private environment value or source map is exposed.
- [ ] Lock diff contains only the reviewed coherent group.
- [ ] Production build and manifest verified with exact commands.
- [ ] Relevant Chromium and real-device/browser journeys verified where available.
- [ ] Immutable asset rollback and service-worker rollback described.
- [ ] Inventory, compatibility, decisions, deployment, README/changelog updated.
