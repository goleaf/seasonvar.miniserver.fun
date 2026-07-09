<?php

return [

    'mail_queue' => env('NOTIFICATIONS_MAIL_QUEUE', 'default'),

    'seasonvar_import_failed' => [
        'mail_to' => env('SEASONVAR_IMPORT_FAILURE_MAIL_TO'),
        'mail_to_name' => env('SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME'),
    ],

];
