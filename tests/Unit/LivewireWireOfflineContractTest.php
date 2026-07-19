<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireOfflineContractTest extends TestCase
{
    public function test_the_layout_keeps_one_global_connectivity_owner(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));
        $runtime = File::get(resource_path('js/mobile-runtime.js'));

        $this->assertStringContainsString('data-connection-status', $layout);
        $this->assertStringContainsString('data-connection-offline', $layout);
        $this->assertStringContainsString('data-connection-restored', $layout);
        $this->assertStringNotContainsString('wire:offline', $layout);
        $this->assertStringContainsString("window.addEventListener('offline', handleOffline)", $runtime);
        $this->assertStringContainsString("window.addEventListener('online', handleOnline)", $runtime);
    }

    public function test_the_long_technical_issue_form_disables_only_submit_while_offline(): void
    {
        $form = File::get(resource_path('views/livewire/technical-issues/form-page.blade.php'));

        $this->assertSame(1, substr_count($form, 'wire:offline.attr="disabled"'));
        $this->assertMatchesRegularExpression(
            '/<button\s+type="submit"\s+wire:offline\.attr="disabled"\s+wire:loading\.attr="disabled"\s+wire:target="submit,screenshots"/',
            $form,
        );
    }
}
