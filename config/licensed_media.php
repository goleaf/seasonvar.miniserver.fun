<?php

$playlistUrls = array_values(array_filter(array_map(
    fn (string $url): string => trim($url),
    explode(',', (string) env('LICENSED_MEDIA_PLAYLIST_URLS', '')),
)));

$episodePathPatterns = array_values(array_filter(array_map(
    fn (string $pattern): string => trim($pattern),
    explode(',', (string) env('LICENSED_MEDIA_EPISODE_PATH_PATTERNS', '{slug}/s{season_pad2}e{episode_pad2}.{extension},{slug}/{season}/{episode}.{extension}')),
)));

return [
    'remote_base_url' => env('LICENSED_MEDIA_BASE_URL'),
    'default_extension' => env('LICENSED_MEDIA_DEFAULT_EXTENSION', 'mp4'),
    'playlist_urls' => $playlistUrls,
    'episode_path_patterns' => $episodePathPatterns,
    'verify_remote_files' => (bool) env('LICENSED_MEDIA_VERIFY_REMOTE_FILES', false),
];
