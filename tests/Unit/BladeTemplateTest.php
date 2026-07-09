<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class BladeTemplateTest extends TestCase
{
    public function test_blade_templates_do_not_use_inline_php_directives(): void
    {
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/@(php|endphp)\b/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Blade templates must move PHP logic into controllers, view-models, or component classes.');
    }
}
