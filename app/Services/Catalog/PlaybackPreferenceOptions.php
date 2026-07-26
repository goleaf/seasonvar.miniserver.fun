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

    /**
     * @param  string|list<string>|null  $selected
     * @return list<array{value: string, label: string, available: bool}>
     */
    public function variants(string|array|null $selected = null, ?User $user = null): array
    {
        return $this->variantOptions($selected, $user);
    }

    /**
     * @param  string|list<string>|null  $selected
     * @return list<array{value: string, label: string, available: bool}>
     */
    public function voiceovers(string|array|null $selected = null, ?User $user = null): array
    {
        return $this->variantOptions($selected, $user, 'voiceover');
    }

    /** @return list<array{value: string, label: string}> */
    public function subtitleLanguages(): array
    {
        return collect((array) config('playback.supported_subtitle_languages', []))
            ->filter(fn (mixed $language): bool => is_string($language) && preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) === 1)
            ->unique()
            ->map(fn (string $language): array => [
                'value' => $language,
                'label' => (string) __('settings.playback.subtitle_languages.'.$language),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  string|list<string>|null  $selected
     * @return list<array{value: string, label: string, available: bool}>
     */
    private function variantOptions(string|array|null $selected, ?User $user, ?string $type = null): array
    {
        $query = LicensedMedia::query()
            ->availableTo($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereNotNull('variant_key')
            ->where('variant_key', '!=', '')
            ->orderBy('variant_key')
            ->limit(250);

        if ($type !== null) {
            $query->where('variant_type', $type);
        }

        $options = $query->get(['variant_key', 'variant_name', 'translation_name'])
            ->unique('variant_key')
            ->filter(fn (LicensedMedia $media): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $media->variant_key) === 1)
            ->map(fn (LicensedMedia $media): array => $this->option(
                (string) $media->variant_key,
                (string) ($media->variant_name ?: $media->translation_name ?: $media->variant_key),
                true,
            ))
            ->values();

        foreach ($this->selectedValues($selected) as $selectedValue) {
            if (! $options->contains('value', $selectedValue)) {
                $options->push($this->option(
                    $selectedValue,
                    $this->unavailableLabel('settings.playback.variant_unavailable', 'variant', $selectedValue),
                    false,
                ));
            }
        }

        return $options->all();
    }

    /**
     * @param  string|list<string>|null  $selected
     * @return list<string>
     */
    private function selectedValues(string|array|null $selected): array
    {
        return collect(is_array($selected) ? $selected : [$selected])
            ->filter(fn (mixed $value): bool => is_string($value)
                && $value !== ''
                && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1)
            ->unique()
            ->values()
            ->all();
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
