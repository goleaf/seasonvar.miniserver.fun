<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePlaybackQualitySampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['context', 'request_id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        foreach (['event', 'browser_family', 'operating_system', 'hls_support', 'error_type', 'network_test_status'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = mb_strtolower(trim($value));
            }
        }

        $this->merge($normalized);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'context' => ['required', 'string', 'max:4096'],
            'request_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(['ready', 'heartbeat', 'error', 'fallback', 'ended', 'report'])],
            'media_id' => ['required', 'integer', 'min:1'],
            'browser_family' => ['nullable', Rule::in(['chromium', 'firefox', 'safari', 'edge', 'opera', 'samsung', 'other', 'unknown'])],
            'browser_major' => ['nullable', 'integer', 'min:1', 'max:999'],
            'operating_system' => ['nullable', Rule::in(['windows', 'macos', 'ios', 'android', 'linux', 'chromeos', 'other', 'unknown'])],
            'hls_support' => ['nullable', Rule::in(['native', 'mse', 'unsupported'])],
            'error_type' => ['nullable', Rule::in(['network', 'media', 'decode', 'manifest', 'segment', 'authorization', 'unsupported', 'timeout', 'unknown'])],
            'startup_time_ms' => ['nullable', 'integer', 'min:0', 'max:300000'],
            'playback_time_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'buffering_time_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'buffering_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'playback_position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'network_test_status' => ['nullable', Rule::in(['ok', 'failed', 'offline', 'timeout'])],
            'network_latency_ms' => ['nullable', 'integer', 'min:0', 'max:30000', 'required_with:network_test_status'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'context.required' => 'Контекст воспроизведения отсутствует.',
            'request_id.uuid' => 'Идентификатор диагностики имеет неверный формат.',
            'media_id.required' => 'Вариант видео не выбран.',
            'event.in' => 'Событие воспроизведения не поддерживается.',
        ];
    }
}
