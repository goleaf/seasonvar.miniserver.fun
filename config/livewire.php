<?php

declare(strict_types=1);

return [
    'inject_assets' => false,

    'temporary_file_upload' => [
        'middleware' => 'throttle:livewire-uploads',
        'rules' => [
            'required',
            'file',
            'max:'.max(1, (int) env('UPLOADS_MAX_IMAGE_KILOBYTES', 2048)),
            'mimetypes:image/jpeg,image/png,image/webp',
        ],
    ],
];
