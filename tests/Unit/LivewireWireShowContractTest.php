<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireShowContractTest extends TestCase
{
    public function test_help_report_form_uses_one_accessible_modifier_free_show_boundary(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $article = File::get(resource_path('views/livewire/help-center/article.blade.php'));

        $this->assertSame(1, substr_count($markup, 'wire:show='));
        $this->assertDoesNotMatchRegularExpression('/wire:show\.[^=\s]+/', $markup);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*wire:click="\$toggle\(\'showReportForm\'\)"[^>]*aria-controls="help-report-form"[^>]*aria-expanded=/s',
            $article,
        );
        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="help-report-form"[^>]*wire:show="showReportForm"[^>]*wire:cloak[^>]*wire:submit="submitReport"/s',
            $article,
        );
        $this->assertStringNotContainsString('@if ($showReportForm)', $article);
    }

    public function test_server_submit_still_owns_validation_reset_and_visibility(): void
    {
        $component = File::get(app_path('Livewire/HelpCenter/HelpArticlePage.php'));

        $this->assertStringContainsString("'reportReason' => ['required', Rule::enum(HelpReportReason::class)]", $component);
        $this->assertStringContainsString("'reportDetails' => ['nullable', 'string', 'max:'", $component);
        $this->assertStringContainsString('$this->showReportForm = false;', $component);
        $this->assertStringContainsString("\$this->reset('reportReason', 'reportDetails');", $component);
    }
}
