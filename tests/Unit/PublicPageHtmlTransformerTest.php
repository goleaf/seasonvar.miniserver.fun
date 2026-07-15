<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cache\PublicPageHtmlTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PublicPageHtmlTransformerTest extends TestCase
{
    public function test_it_masks_and_restores_request_specific_html_values(): void
    {
        $this->startSession();
        $originalToken = csrf_token();
        $originalPlaybackUrl = URL::temporarySignedRoute(
            'playback.source',
            now()->addMinute(),
            ['licensedMedia' => 42, 'viewer' => 0],
        );
        $html = sprintf(
            '<script data-csrf="%s"></script><video data-hls-src="%s"><source src="%s"></video>',
            e($originalToken),
            e($originalPlaybackUrl),
            e($originalPlaybackUrl),
        );

        $sanitized = app(PublicPageHtmlTransformer::class)->sanitize($html);

        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString($originalToken, $sanitized);
        $this->assertStringNotContainsString('signature=', $sanitized);
        $this->assertStringNotContainsString('expires=', $sanitized);
        $this->assertStringNotContainsString($originalPlaybackUrl, html_entity_decode($sanitized));

        $this->travel(70)->seconds();
        session()->regenerateToken();
        $currentToken = csrf_token();
        $restored = app(PublicPageHtmlTransformer::class)->restore($sanitized);

        $this->assertNotSame($originalToken, $currentToken);
        $this->assertStringContainsString('data-csrf="'.e($currentToken).'"', $restored);

        preg_match('/<source src="([^"]+)"/', $restored, $match);
        $restoredPlaybackUrl = html_entity_decode((string) ($match[1] ?? ''));

        $this->assertNotSame($originalPlaybackUrl, $restoredPlaybackUrl);
        $this->assertTrue(Request::create($restoredPlaybackUrl)->hasValidSignature());
        $this->assertStringContainsString('/playback/42?', $restoredPlaybackUrl);
        $this->assertSame(2, substr_count($restored, e($restoredPlaybackUrl)));
    }

    public function test_it_does_not_mask_an_external_signed_looking_url(): void
    {
        $this->startSession();
        $external = 'https://evil.example/playback/42?expires=9999999999&signature=fake';
        $html = '<script data-csrf="'.e(csrf_token()).'"></script><source src="'.e($external).'">';

        $sanitized = app(PublicPageHtmlTransformer::class)->sanitize($html);

        $this->assertIsString($sanitized);
        $this->assertStringContainsString(e($external), $sanitized);
    }

    public function test_it_rejects_livewire_html_when_the_current_csrf_is_missing(): void
    {
        $this->startSession();

        $sanitized = app(PublicPageHtmlTransformer::class)->sanitize(
            '<script src="/livewire/livewire.js" data-csrf="not-the-current-token"></script>',
        );

        $this->assertNull($sanitized);
    }

    public function test_it_rejects_html_that_already_contains_reserved_markers(): void
    {
        $this->startSession();

        foreach ([
            '__SEASONVAR_PUBLIC_PAGE_CSRF__',
            '__SEASONVAR_PUBLIC_PAGE_PLAYBACK_42__',
        ] as $marker) {
            $this->assertNull(app(PublicPageHtmlTransformer::class)->sanitize(
                '<script data-csrf="'.e(csrf_token()).'"></script><p>'.$marker.'</p>',
            ));
        }
    }
}
