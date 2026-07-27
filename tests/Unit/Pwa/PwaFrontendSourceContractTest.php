<?php

declare(strict_types=1);

namespace Tests\Unit\Pwa;

use PHPUnit\Framework\TestCase;

final class PwaFrontendSourceContractTest extends TestCase
{
    public function test_runtime_uses_bounded_indexed_db_and_requires_a_user_gesture_for_push(): void
    {
        $runtime = file_get_contents(dirname(__DIR__, 3).'/resources/js/pwa.js');
        $storage = file_get_contents(dirname(__DIR__, 3).'/resources/js/pwa-storage.js');
        $delivery = file_get_contents(dirname(__DIR__, 3).'/app/Services/Pwa/WebPushDeliveryService.php');

        $this->assertIsString($runtime);
        $this->assertIsString($storage);
        $this->assertIsString($delivery);

        foreach ([
            'navigator.serviceWorker.register',
            'window.isSecureContext',
            'Notification.requestPermission',
            "'Notification' in window",
            'addEventListener(\'click\'',
            'pushManager.subscribe',
            'pushManager.getSubscription',
            'addEventListener(\'online\'',
            'data-pwa-logout',
            'event.preventDefault()',
            'event.stopImmediatePropagation()',
            'pwaLogoutCleanupComplete',
            'clearPreviousAccountScope',
            'error.status === 401 || error.status === 403',
            'MAX_POSTER_PREFETCH = 12',
            'POSTER_PREFETCH_CONCURRENCY = 3',
            "'rating.set'",
            'updateLocalRating',
            "['applied', 'duplicate'].includes(result.status)",
            'reconcileSafeActionResults',
            'readSafeActionIssues',
            'toLocaleString',
            'replaceChildren()',
            'URL.revokeObjectURL',
        ] as $required) {
            $this->assertStringContainsString($required, $runtime);
        }

        foreach ([
            'MAX_LIBRARY_ITEMS = 300',
            'MAX_HELP_ITEMS = 60',
            'MAX_QUEUE_ITEMS = 100',
            'MAX_QUEUE_BATCH = 50',
            'QUEUE_RETENTION_DAYS = 30',
            'indexedDB.open',
            'accountScope',
            "ACTION_STORE, 'readwrite'",
            'record.queued_at < cutoff',
        ] as $required) {
            $this->assertStringContainsString($required, $storage);
        }

        foreach ([$runtime, $storage] as $source) {
            $this->assertStringNotContainsString('innerHTML', $source);
            $this->assertStringNotContainsString('localStorage', $source);
            $this->assertStringNotContainsString('playback_session', $source);
            $this->assertStringNotContainsString('source_url', $source);
            $this->assertStringNotContainsString('Authorization', $source);
        }

        $this->assertStringNotContainsString(
            "['applied', 'duplicate', 'conflict'",
            $runtime,
        );
        $this->assertStringContainsString("'allow_redirects' => false", $delivery);
        $this->assertStringContainsString('instanceof ConnectionException', $delivery);
        $this->assertStringContainsString('response->status() === 429', $delivery);
    }
}
