<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Catalog\PlaybackPreferenceOptions;
use App\ValueObjects\AccountTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MigrateAnonymousPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('update-account-settings');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $variantKeys = collect(app(PlaybackPreferenceOptions::class)->variants(user: $this->user()))->pluck('value')->all();
        $qualityKeys = collect(app(PlaybackPreferenceOptions::class)->qualities(user: $this->user()))->pluck('value')->all();
        $maximumDuration = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));

        return [
            'version' => ['required', 'integer', Rule::in([1])],
            'locale' => ['sometimes', 'nullable', Rule::in((array) config('catalog-collections.supported_locales', []))],
            'timezone' => ['sometimes', 'nullable', Rule::in(AccountTimezone::identifiers())],
            'autoplay' => ['sometimes', 'nullable', 'boolean'],
            'remember_volume' => ['sometimes', 'nullable', 'boolean'],
            'volume' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'muted' => ['sometimes', 'nullable', 'boolean'],
            'playback_speed' => ['sometimes', 'nullable', Rule::in((array) config('account-settings.playback_speeds', []))],
            'preferred_quality' => ['sometimes', 'nullable', Rule::in($qualityKeys)],
            'preferred_variant' => ['sometimes', 'nullable', Rule::in($variantKeys)],
            'subtitles_enabled' => ['sometimes', 'nullable', 'boolean'],
            'keyboard_shortcuts_enabled' => ['sometimes', 'nullable', 'boolean'],
            'reduced_motion' => ['sometimes', 'nullable', 'boolean'],
            'playback_progress' => ['sometimes', 'array', 'max:50'],
            'playback_progress.*' => ['required', 'array:episode_id,position,duration,completed,updated_at'],
            'playback_progress.*.episode_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'playback_progress.*.position' => ['required', 'integer', 'min:0', 'max:'.$maximumDuration],
            'playback_progress.*.duration' => ['required', 'integer', 'min:0', 'max:'.$maximumDuration],
            'playback_progress.*.completed' => ['required', 'boolean'],
            'playback_progress.*.updated_at' => ['required', 'integer', 'min:1'],
        ];
    }
}
