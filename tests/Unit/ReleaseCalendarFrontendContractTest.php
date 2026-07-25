<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ReleaseCalendarFrontendContractTest extends TestCase
{
    public function test_private_feed_actions_are_local_clipboard_safe_and_livewire_reinitializable(): void
    {
        $runtime = File::get(resource_path('js/release-calendar.js'));
        $app = File::get(resource_path('js/app.js'));
        $view = File::get(resource_path(
            'views/livewire/release-calendar/release-calendar-feed-manager.blade.php',
        ));

        $this->assertStringContainsString('url.origin !== window.location.origin', $runtime);
        $this->assertStringContainsString('/^\\/calendar\\/feed\\/[A-Za-z0-9_-]{64}\\.ics$/', $runtime);
        $this->assertStringContainsString('navigator.clipboard?.writeText', $runtime);
        $this->assertStringContainsString("document.execCommand('copy')", $runtime);
        $this->assertStringContainsString('initializeReleaseCalendarInterfaces', $runtime);
        $this->assertStringNotContainsString('localStorage', $runtime);
        $this->assertStringNotContainsString('sessionStorage', $runtime);
        $this->assertStringContainsString('[data-calendar-copy], [data-calendar-google]', $app);
        $this->assertStringContainsString('data-calendar-copy', $view);
        $this->assertStringContainsString('data-calendar-google', $view);
        $this->assertStringContainsString('rel="noopener noreferrer"', $view);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $view);
        $this->assertStringNotContainsString('<script', $view);
        $this->assertStringNotContainsString('@php', $view);
    }
}
