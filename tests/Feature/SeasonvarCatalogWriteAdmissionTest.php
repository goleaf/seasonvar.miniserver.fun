<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Seasonvar\SeasonvarCatalogWriteAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SeasonvarCatalogWriteAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.import.writer_admission_enabled' => true,
        ]);
        Cache::store('array')->flush();
    }

    public function test_sqlite_allows_only_one_catalog_writer_at_a_time(): void
    {
        $admission = app(SeasonvarCatalogWriteAdmission::class);
        $first = $admission->acquire(120);
        $second = $admission->acquire(120);

        $this->assertTrue($admission->required());
        $this->assertNotNull($first);
        $this->assertNull($second);

        $first->release();
        $third = $admission->acquire(120);

        $this->assertNotNull($third);
        $third->release();
    }

    public function test_disabled_rollout_does_not_require_a_writer_lock(): void
    {
        config(['seasonvar.import.writer_admission_enabled' => false]);
        $admission = app(SeasonvarCatalogWriteAdmission::class);

        $this->assertFalse($admission->required());
        $this->assertNull($admission->acquire(120));
    }
}
