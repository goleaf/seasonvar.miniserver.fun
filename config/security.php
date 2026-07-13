<?php

return [
    'external_playlist_enforce_public_dns' => filter_var(env('EXTERNAL_PLAYLIST_ENFORCE_PUBLIC_DNS', true), FILTER_VALIDATE_BOOL),
];
