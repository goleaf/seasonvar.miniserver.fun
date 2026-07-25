<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaFileSizeCheckStatus;
use App\Models\LicensedMedia;
use App\Services\Media\ExternalMediaFileSizeInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalMediaFileSizeInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_size_only_import_uses_head_then_a_single_byte_range_fallback(): void
    {
        config([
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.media_file_size.retry_times' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'HEAD') {
                return Http::response('', 405);
            }

            return Http::response('', 206, [
                'Content-Type' => 'video/mp4',
                'Content-Range' => 'bytes 0-0/123456789',
                'Accept-Ranges' => 'bytes',
            ]);
        });
        $media = LicensedMedia::factory()->create([
            'path' => 'https://1.1.1.1/video/ryzhaya-s01e01.mp4',
            'playback_url' => 'https://1.1.1.1/video/ryzhaya-s01e01.mp4',
            'format' => 'mp4',
            'status' => 'published',
            'file_size_bytes' => null,
            'file_size_checked_at' => null,
            'file_size_check_status' => MediaFileSizeCheckStatus::Pending,
        ]);

        $this->artisan('seasonvar:import', [
            '--refresh-media-sizes' => true,
            '--media-size-limit' => 1,
        ])->assertExitCode(0);

        $media->refresh();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === $media->playback_url);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Range', 'bytes=0-0')
            && $request->url() === $media->playback_url);
        $this->assertSame(MediaFileSizeCheckStatus::Known, $media->file_size_check_status);
        $this->assertSame(123456789, $media->file_size_bytes);
        $this->assertSame('ranged-content-range', $media->file_size_source);
        $this->assertSame(206, $media->file_size_http_status);
    }

    public function test_hls_manifest_is_not_treated_as_a_complete_video_file(): void
    {
        Http::preventStrayRequests();
        $media = LicensedMedia::factory()->create([
            'path' => 'https://1.1.1.1/video/ryzhaya-s01e01.m3u8',
            'playback_url' => 'https://1.1.1.1/video/ryzhaya-s01e01.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
        ]);

        $result = app(ExternalMediaFileSizeInspector::class)->inspect($media);

        Http::assertNothingSent();
        $this->assertSame(MediaFileSizeCheckStatus::Unsupported, $result->status);
        $this->assertNull($result->bytes);
        $this->assertSame('playlist_manifest', $result->errorCategory);
        $this->assertSame('playlist_manifest_is_not_complete_video', $result->safeErrorMessage);
    }
}
