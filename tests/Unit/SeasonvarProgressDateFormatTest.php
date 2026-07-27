<?php

namespace Tests\Unit;

use App\Console\Presenters\SeasonvarProgressPresenter;
use App\Support\HumanFileSizeFormatter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SeasonvarProgressDateFormatTest extends TestCase
{
    public function test_it_formats_progress_dates_in_european_datetime_format(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');
        $presenter = new SeasonvarProgressPresenter(new HumanFileSizeFormatter);
        $presentation = $presenter->present(
            'test-event',
            [
                'object' => Carbon::parse('2026-07-09 10:11:30'),
                'string' => '2026-07-09 10:11:30',
            ],
            [],
            [],
            [],
        );

        $this->assertSame(
            '[09.07.2026 12:00] test event: object=09.07.2026 10:11 | string=09.07.2026 10:11',
            $presentation['message'],
        );
    }
}
