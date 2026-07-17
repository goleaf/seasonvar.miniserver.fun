<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private Upload Storage
    |--------------------------------------------------------------------------
    |
    | User supplied files must be stored on a private disk by default. Public
    | file delivery should be implemented separately with authorization and
    | signed URLs when a concrete feature needs it.
    |
    */

    'disk' => env('UPLOADS_DISK', 'uploads'),

    'visibility' => 'private',

    'runtime_group' => env('UPLOADS_RUNTIME_GROUP', 'www'),

    'max_image_kilobytes' => (int) env('UPLOADS_MAX_IMAGE_KILOBYTES', 2048),

    'image_extensions' => [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ],

];
