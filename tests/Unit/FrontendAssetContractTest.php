<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendAssetContractTest extends TestCase
{
    public function test_frontend_assets_are_local_and_cyrillic_safe(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $player = File::get(resource_path('js/player.js'));
        $styles = File::get(resource_path('css/app.css'));
        $vite = File::get(base_path('vite.config.js'));
        $layout = File::get(resource_path('views/layouts/app.blade.php'));
        $npmConfig = File::get(base_path('.npmrc'));
        $npmLock = File::get(base_path('package-lock.json'));

        $this->assertStringNotContainsString('all.min.css', $app);
        $this->assertStringContainsString('fontawesome.min.css', $app);
        $this->assertStringContainsString('solid.min.css', $app);
        $this->assertStringContainsString('regular.min.css', $app);
        $this->assertStringContainsString('../images/plyr.svg?url', $player);
        $this->assertStringContainsString('iconUrl: plyrIconUrl', $player);
        $this->assertStringNotContainsString('cdn.plyr.io', $player);
        $this->assertStringNotContainsString('Instrument Sans', $styles);
        $this->assertStringNotContainsString("bunny('Instrument Sans'", $vite);
        $this->assertStringNotContainsString("Vite::fonts('instrument-sans')", $layout);
        $this->assertStringContainsString('registry=https://registry.npmjs.org/', $npmConfig);
        $this->assertStringNotContainsString('registry.npmmirror.com', $npmLock);
    }
}
