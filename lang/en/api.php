<?php

return [
    'errors' => [
        'validation_failed' => 'The submitted data is invalid.',
    ],
    'sync' => [
        'bootstrap' => 'Save the cursor, load the current catalog page by page, then request changes from the saved cursor.',
        'cursor_expired' => 'The synchronization cursor has expired. Perform a full bootstrap again.',
        'cursor_invalid' => 'The synchronization cursor is invalid.',
        'unavailable' => 'Synchronization is temporarily unavailable.',
    ],
];
