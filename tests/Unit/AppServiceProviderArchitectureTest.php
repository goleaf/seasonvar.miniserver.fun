<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppServiceProviderArchitectureTest extends TestCase
{
    public function test_non_production_enables_all_eloquent_strictness_guards(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
    }

    public function test_queue_loop_rolls_back_transactions_left_by_a_previous_job(): void
    {
        DB::beginTransaction();

        try {
            $this->assertSame(1, DB::transactionLevel());

            Event::dispatch(new Looping('redis', 'seasonvar-import'));

            $this->assertSame(0, DB::transactionLevel());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
