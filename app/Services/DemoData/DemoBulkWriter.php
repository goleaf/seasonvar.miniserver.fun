<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use Illuminate\Support\Facades\DB;

final readonly class DemoBulkWriter
{
    public function __construct(private DemoDataOptions $options) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    public function upsert(string $table, array $rows, array $uniqueBy, array $update): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::connection()->disableQueryLog();
        $affected = 0;

        foreach (array_chunk($rows, $this->options->chunkSize) as $chunk) {
            $affected += DB::transaction(
                fn (): int => $update === []
                    ? DB::table($table)->insertOrIgnore($chunk)
                    : DB::table($table)->upsert($chunk, $uniqueBy, $update),
                attempts: 3,
            );
        }

        return $affected;
    }
}
