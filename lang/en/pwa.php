<?php

declare(strict_types=1);

return [
    'manifest' => [
        'description' => 'Seasonvar series catalog with safe offline access to saved library metadata and help.',
    ],
    'offline' => [
        'eyebrow' => 'No connection',
        'title' => 'Saved copy',
        'description' => 'Previously saved library details and public help remain available. Safe queued changes will be sent when the connection returns.',
        'saved_copy_title' => 'Available offline',
        'saved_copy_body' => 'Saved library metadata, posters and help articles can be opened without a connection.',
        'video_title' => 'Video is unavailable offline',
        'video_body' => 'HLS, protected video and external sources are never saved to the device. Playback requires a network connection and a fresh access check.',
        'library_title' => 'Saved library',
        'library_hint' => 'Only minimal details saved for the most recent signed-in account on this device are shown.',
        'library_empty' => 'No library snapshot has been saved on this device yet.',
        'help_title' => 'Saved help',
        'help_hint' => 'Only published public articles are available.',
        'help_empty' => 'Help has not been saved on this device yet.',
        'add_to_library' => 'Add to library',
        'remove_from_library' => 'Remove from library',
        'rating_label' => 'Rating',
        'rating_clear' => 'No rating',
        'action_queued' => 'The change was saved and will be checked by the server when the connection returns.',
        'action_requires_attention' => 'Some changes were not applied by the server and need review.',
        'saved_at' => 'Saved',
        'retry' => 'Try again',
        'help' => 'Open help',
    ],
    'push' => [
        'notification_title' => 'Seasonvar',
        'notification_body' => 'You have a new notification.',
    ],
    'validation' => [
        'locale_required' => 'Choose a help language.',
        'locale_invalid' => 'The selected help language is not supported.',
        'query_invalid' => 'The help request contains unsupported parameters.',
        'operations_required' => 'Provide actions to synchronize.',
        'operations_list' => 'Actions must be provided as a list.',
        'operations_minimum' => 'Add at least one action.',
        'operations_maximum' => 'No more than :max actions can be synchronized at once.',
        'field_unsupported' => 'This field is not supported by the safe offline queue.',
        'watchlist_boolean' => 'The watchlist state must be a boolean.',
        'rating_range' => 'The rating must be an integer from :minimum to :maximum or null.',
        'version_integer' => 'The resource version must be a non-negative integer.',
        'title_slug_invalid' => 'The title address is invalid.',
        'push_endpoint_invalid' => 'The browser returned an unsupported push service endpoint.',
    ],
];
