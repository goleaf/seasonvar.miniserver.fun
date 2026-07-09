<?php

namespace Tests\Unit;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SeasonvarProgressDateFormatTest extends TestCase
{
    public function test_it_formats_progress_dates_in_european_datetime_format(): void
    {
        $formatter = new class extends Command
        {
            use OutputsSeasonvarProgress {
                formatSeasonvarValue as public exposedFormatSeasonvarValue;
            }
        };

        $this->assertSame('09.07.2026 10:11', $formatter->exposedFormatSeasonvarValue(Carbon::parse('2026-07-09 10:11:30')));
        $this->assertSame('09.07.2026 10:11', $formatter->exposedFormatSeasonvarValue('2026-07-09 10:11:30'));
    }
}
