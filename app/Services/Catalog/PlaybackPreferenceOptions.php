<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\LicensedMedia;
use App\Models\User;

final class PlaybackPreferenceOptions
{
    /** @return list<array{value: string, label: string, available: bool}> */
    public function qualities(?string $selected = null, ?User $user = null): array
    {
        $supported = (array) config('playback.supported_qualities', []);
        $options = LicensedMedia::query()
            ->availableTo($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereNotNull('quality')
            ->whereIn('quality', $supported)
            ->orderBy('quality')
            ->distinct()
            ->pluck('quality')
            ->filter(fn (mixed $quality): bool => is_string($quality) && in_array($quality, $supported, true))
            ->map(fn (string $quality): array => $this->option($quality, $quality, true))
            ->values();

        if ($selected !== null && in_array($selected, $supported, true) && ! $options->contains('value', $selected)) {
            $options->push($this->option(
                $selected,
                $this->unavailableLabel('settings.playback.quality_unavailable', 'quality', $selected),
                false,
            ));
        }

        return $options->all();
    }

    /** @return list<array{value: string, label: string, available: bool}> */
    public function variants(?string $selected = null, ?User $user = null): array
    {
        $options = LicensedMedia::query()
            ->availableTo($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereNotNull('variant_key')
            ->where('variant_key', '!=', '')
            ->orderBy('variant_key')
            ->limit(250)
            ->get(['variant_key', 'variant_name', 'translation_name'])
            ->unique('variant_key')
            ->filter(fn (LicensedMedia $media): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $media->variant_key) === 1)
            ->map(fn (LicensedMedia $media): array => $this->option(
                (string) $media->variant_key,
                (string) ($media->variant_name ?: $media->translation_name ?: $media->variant_key),
                true,
            ))
            ->values();

        if ($selected !== null && $selected !== '' && ! $options->contains('value', $selected)) {
            $options->push($this->option(
                $selected,
                $this->unavailableLabel('settings.playback.variant_unavailable', 'variant', $selected),
                false,
            ));
        }

        return $options->all();
    }

    /** @return array{value: string, label: string, available: bool} */
    private function option(string $value, string $label, bool $available): array
    {
        return [
            'value' => $value,
            'label' => $label,
            'available' => $available,
        ];
    }

    private function unavailableLabel(string $key, string $placeholder, string $value): string
    {
        $translated = __($key, [$placeholder => $value]);

        return is_string($translated) ? $translated : $value;
    }
}
