<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class AppShellFocusTest extends TestCase
{
    public function test_programmatically_focused_main_container_has_no_decorative_green_outline(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression('/\.app-shell-main:focus-visible\s*\{[^}]*outline:\s*none;[^}]*box-shadow:\s*none;/s', $css);
    }
}
