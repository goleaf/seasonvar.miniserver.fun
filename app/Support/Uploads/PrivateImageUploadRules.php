<?php

namespace App\Support\Uploads;

use Illuminate\Validation\Rules\File;

final class PrivateImageUploadRules
{
    /**
     * @return array<int, mixed>
     */
    public static function required(): array
    {
        return ['required', self::file()];
    }

    /**
     * @return array<int, mixed>
     */
    public static function nullable(): array
    {
        return ['nullable', self::file()];
    }

    public static function file(): File
    {
        return File::image(allowSvg: false)
            ->extensions(config('uploads.image_extensions', []))
            ->max((int) config('uploads.max_image_kilobytes', 2048));
    }
}
