# Seasonvar current-season gap design

## Problem

Some canonical Seasonvar pages do not include a season suffix in their URL and represent season 1. Their `pgs-seaslist` can contain links only to other seasons. The parser therefore returns, for example, season 2 from the link list while `arEpisodes` correctly assigns the current page episodes to season 1. Persistence cannot attach those episodes because season 1 was never created, and the page is incorrectly marked `no_episodes`.

Production page `26338` demonstrates the mismatch: its stored snapshot parses to eight season-1 episodes, but the catalog contains only season 2 with zero episodes.

## Chosen approach

The parser will guarantee that `seasons` contains `current_season_number`. It will first determine the current season from the page URL or title, falling back to 1. It will parse linked seasons as before, then add a synthetic current-season entry only when that number is absent.

The synthetic entry uses the current page URL, the title `Сезон N`, and empty release-status metadata. Existing linked metadata wins when the current season is already present.

This keeps parsed output internally consistent before it reaches database synchronization. The importer will not guess an unmatched episode's destination and persistence boundaries remain unchanged.

## Data flow

1. Determine `current_season_number` from URL, title, or fallback 1.
2. Parse direct Seasonvar season links from `pgs-seaslist`.
3. Add the current season when it is missing from the parsed link map.
4. Parse `arEpisodes` using the same current-season number.
5. Existing `syncSeasons()` creates or restores the season; existing `syncEpisodes()` attaches episodes through the exact season-number key.
6. Existing missing-data evaluation sees the stored episodes and removes false `no_episodes` flags.

## Alternatives rejected

- Attaching unmatched episodes to the first available season can silently put season-1 episodes into season 2.
- Creating seasons from inside `syncEpisodes()` mixes parsing inference with persistence and duplicates `syncSeasons()` responsibility.

## Compatibility and safety

- Pages whose season list already contains the current season are unchanged.
- Explicit season URLs continue to use their parsed season number.
- Only allowed, normalized Seasonvar URLs are stored.
- No video files are downloaded and no new dependency or migration is required.

## Tests

- A parser unit test reproduces a canonical page whose list contains only season 2 while `arEpisodes` contains season-1 episodes.
- The test asserts that seasons 1 and 2 are both returned, season 1 points to the canonical page, and all episodes reference season 1.
- Existing parser and importer tests verify unchanged behavior for explicit season URLs and normal multi-season lists.
