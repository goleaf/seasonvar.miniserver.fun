# Sanitized Seasonvar parser corpus

These fixtures are minimal synthetic documents. They contain no copied page
bodies, credentials, private paths, cookies, signed provider values or full
video payloads.

| Fixture | Contract |
|---|---|
| `complete-serial.html` | Complete metadata, season, episode and direct-media observations |
| `season-family-30.html` | One title family with 30 season links |
| `missing-info.html` | Metadata structure is unknown, not authoritatively empty |
| `missing-seasons.html` | Season list is unknown while episode data remains usable |
| `malformed-script.html` | A truncated episode payload is invalid/partial and bounded |
| `region-blocked.html` | Rights-holder regional block never claims complete media data |
| `duplicates-unicode.html` | Duplicate metadata and Unicode punctuation normalize deterministically |
| `partial.html` | Truncated markup remains additive-only |
| `large-episodes.json` | Parameters for a generated bounded 2,600-episode fixture |

Nested playlist folders, HLS master/media playlists and volatile playlist
queries are covered by `ExternalPlaylistImportTest`; the parser corpus only
stores the sanitized source-page candidate boundary.
