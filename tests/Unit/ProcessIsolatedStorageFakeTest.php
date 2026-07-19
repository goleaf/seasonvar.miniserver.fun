<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProcessIsolatedStorageFakeTest extends TestCase
{
    public function test_serial_process_scopes_storage_fakes_to_its_process_token(): void
    {
        $expectedToken = $_SERVER['TEST_TOKEN'] ?? (string) getmypid();

        $this->assertSame($expectedToken, ParallelTesting::token());

        $disk = Storage::fake('uploads');

        $this->assertStringContainsString(
            'storage/framework/testing/disks/uploads_test_'.$expectedToken,
            $disk->path('probe'),
        );
    }
}
