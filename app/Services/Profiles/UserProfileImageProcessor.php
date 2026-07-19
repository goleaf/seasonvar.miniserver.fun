<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Profiles\PreparedUserProfileImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

final class UserProfileImageProcessor
{
    public function process(UploadedFile $file, string $kind): PreparedUserProfileImage
    {
        [$targetWidth, $targetHeight] = $this->target($kind);
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Не удалось прочитать загруженное изображение профиля.');
        }

        $maximumBytes = max(1, (int) config(
            'user-profiles.uploads.'.$kind.'_maximum_kilobytes',
            $kind === 'avatar' ? 3072 : 6144,
        )) * 1024;
        $size = filesize($path);

        if (! is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new RuntimeException('Размер изображения профиля вышел за допустимые границы.');
        }

        $bytes = file_get_contents($path);

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $maximumBytes) {
            throw new RuntimeException('Не удалось безопасно прочитать изображение профиля.');
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
        $maximumDimension = max(1, (int) config('user-profiles.uploads.maximum_source_dimension', 6000));
        $maximumPixels = max(1, (int) config('user-profiles.uploads.maximum_source_pixels', 24_000_000));

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $width < 1
            || $height < 1
            || $width > $maximumDimension
            || $height > $maximumDimension
            || $width * $height > $maximumPixels) {
            throw new RuntimeException('Формат или размеры изображения профиля недопустимы.');
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new RuntimeException('Изображение профиля не удалось декодировать.');
        }

        try {
            $source = $this->orient($source, $path, $mime);
            $encoded = $this->cropAndEncode($source, $targetWidth, $targetHeight);
        } finally {
            imagedestroy($source);
        }

        $outputInfo = @getimagesizefromstring($encoded);

        if (! is_array($outputInfo)
            || ($outputInfo['mime'] ?? null) !== 'image/webp'
            || (int) ($outputInfo[0] ?? 0) !== $targetWidth
            || (int) ($outputInfo[1] ?? 0) !== $targetHeight) {
            throw new RuntimeException('WebP-изображение профиля не прошло итоговую проверку.');
        }

        return new PreparedUserProfileImage(
            bytes: $encoded,
            mimeType: 'image/webp',
            extension: 'webp',
            size: strlen($encoded),
            width: $targetWidth,
            height: $targetHeight,
        );
    }

    /** @return array{int, int} */
    private function target(string $kind): array
    {
        if (! in_array($kind, ['avatar', 'cover'], true)) {
            throw new RuntimeException('Неизвестный тип изображения профиля.');
        }

        $defaults = $kind === 'avatar' ? [320, 320] : [1280, 360];

        return [
            max(32, min(4096, (int) config("user-profiles.uploads.{$kind}_width", $defaults[0]))),
            max(32, min(4096, (int) config("user-profiles.uploads.{$kind}_height", $defaults[1]))),
        ];
    }

    private function orient(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $exif = @exif_read_data($path, 'IFD0', true, false);
        } catch (Throwable) {
            return $image;
        }

        $orientation = is_array($exif) ? (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('Не удалось применить ориентацию изображения профиля.');
        }

        imagedestroy($image);

        return $rotated;
    }

    private function cropAndEncode(GdImage $source, int $targetWidth, int $targetHeight): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $width / $height;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $height;
            $cropWidth = max(1, (int) round($height * $targetRatio));
            $sourceX = max(0, intdiv($width - $cropWidth, 2));
            $sourceY = 0;
        } else {
            $cropWidth = $width;
            $cropHeight = max(1, (int) round($width / $targetRatio));
            $sourceX = 0;
            $sourceY = max(0, intdiv($height - $cropHeight, 2));
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $target instanceof GdImage) {
            throw new RuntimeException('Не удалось подготовить размер изображения профиля.');
        }

        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

            if (! imagecopyresampled(
                $target,
                $source,
                0,
                0,
                $sourceX,
                $sourceY,
                $targetWidth,
                $targetHeight,
                $cropWidth,
                $cropHeight,
            )) {
                throw new RuntimeException('Не удалось изменить размер изображения профиля.');
            }

            $bufferLevel = ob_get_level();
            ob_start();

            try {
                $written = imagewebp(
                    $target,
                    null,
                    max(1, min(100, (int) config('user-profiles.uploads.webp_quality', 82))),
                );
                $bytes = ob_get_clean();
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
        } finally {
            imagedestroy($target);
        }

        if (! $written || ! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Не удалось создать WebP-изображение профиля.');
        }

        return $bytes;
    }
}
