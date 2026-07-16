<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final readonly class DemoRasterAsset
{
    public function __construct(
        private DemoDataOptions $options,
        private DemoStableValue $stable,
    ) {}

    /**
     * @return array{disk: string, path: string, mime_type: string, size: int, width: int, height: int, hash: string}
     */
    public function store(string $kind, string $identity, int $width, int $height): array
    {
        if ($kind === '' || $identity === '' || $width < 32 || $height < 32 || $width > 2_000 || $height > 2_000) {
            throw new InvalidArgumentException('Demo raster asset parameters are invalid.');
        }

        $scope = 'asset:'.$kind.':'.$identity.':'.$width.'x'.$height;
        $path = trim($this->options->assetPrefix, '/')
            .'/'.trim($kind, '/')
            .'/'.$this->stable->uuid($scope).'.png';
        $bytes = $this->png($scope, $kind, $width, $height);
        $disk = Storage::disk($this->options->assetDisk);

        if (! $disk->put($path, $bytes)) {
            throw new RuntimeException('Unable to store deterministic demo raster asset.');
        }

        $disk->setVisibility($path, 'private');

        return [
            'disk' => $this->options->assetDisk,
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($bytes),
            'width' => $width,
            'height' => $height,
            'hash' => hash('sha256', $bytes),
        ];
    }

    private function png(string $scope, string $kind, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('Unable to create GD image.');
        }

        [$red, $green, $blue] = $this->rgb($scope.':background', 58, 42);
        [$accentRed, $accentGreen, $accentBlue] = $this->rgb($scope.':accent', 72, 68);
        $background = imagecolorallocate($image, $red, $green, $blue);
        $accent = imagecolorallocate($image, $accentRed, $accentGreen, $accentBlue);
        $foreground = imagecolorallocate($image, 248, 246, 240);

        imagefill($image, 0, 0, $background);
        imagefilledellipse(
            $image,
            $this->stable->integer($scope.':circle-x', intdiv($width, 4), intdiv($width * 3, 4)),
            $this->stable->integer($scope.':circle-y', intdiv($height, 4), intdiv($height * 3, 4)),
            max(24, intdiv($width, 2)),
            max(24, intdiv($height, 2)),
            $accent,
        );
        imagefilledrectangle(
            $image,
            0,
            intdiv($height * 4, 5),
            $width,
            $height,
            $accent,
        );

        $label = strtoupper(substr(preg_replace('/[^a-z]/i', '', $kind) ?: 'SV', 0, 2));
        imagestring(
            $image,
            5,
            max(4, intdiv($width - imagefontwidth(5) * strlen($label), 2)),
            max(4, intdiv($height - imagefontheight(5), 2)),
            $label,
            $foreground,
        );

        ob_start();
        $written = imagepng($image, null, 9);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! $written || ! is_string($bytes)) {
            throw new RuntimeException('Unable to encode GD image.');
        }

        return $bytes;
    }

    /** @return array{int, int, int} */
    private function rgb(string $scope, int $saturation, int $lightness): array
    {
        $hue = $this->stable->integer($scope.':hue', 0, 359) / 360;
        $saturation /= 100;
        $lightness /= 100;
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $segment = $hue * 6;
        $secondary = $chroma * (1 - abs(fmod($segment, 2) - 1));
        [$red, $green, $blue] = match ((int) floor($segment) % 6) {
            0 => [$chroma, $secondary, 0.0],
            1 => [$secondary, $chroma, 0.0],
            2 => [0.0, $chroma, $secondary],
            3 => [0.0, $secondary, $chroma],
            4 => [$secondary, 0.0, $chroma],
            default => [$chroma, 0.0, $secondary],
        };
        $match = $lightness - $chroma / 2;

        return [
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255),
        ];
    }
}
