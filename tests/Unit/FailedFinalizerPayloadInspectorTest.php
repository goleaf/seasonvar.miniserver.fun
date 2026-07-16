<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Services\Operations\FailedFinalizerPayloadInspector;
use Tests\TestCase;

final class FailedFinalizerPayloadInspectorTest extends TestCase
{
    public function test_it_extracts_only_exact_allowlisted_scalar_finalizer_targets(): void
    {
        $this->assertTrue(class_exists(FailedFinalizerPayloadInspector::class));

        $inspector = app(FailedFinalizerPayloadInspector::class);

        $this->assertSame([
            'type' => 'title_group',
            'target_id' => 123,
        ], $inspector->inspect($this->payload(new FinalizeSeasonvarImportTitleGroup(123))));
        $this->assertSame([
            'type' => 'global_run',
            'target_id' => 456,
        ], $inspector->inspect($this->payload(new FinalizeSeasonvarQueuedImport(456))));

        $delayed = (new FinalizeSeasonvarImportTitleGroup(789))->delay(now()->addMinute());

        $this->assertSame([
            'type' => 'title_group',
            'target_id' => 789,
        ], $inspector->inspect($this->payload($delayed)));
    }

    public function test_it_rejects_malformed_oversized_mismatched_and_nested_envelopes_without_deserialization(): void
    {
        $this->assertTrue(class_exists(FailedFinalizerPayloadInspector::class));

        $inspector = app(FailedFinalizerPayloadInspector::class);
        $class = FinalizeSeasonvarImportTitleGroup::class;
        $nestedCommand = serialize((object) ['groupId' => 123]);

        $this->assertNull($inspector->inspect('{malformed'));
        $this->assertNull($inspector->inspect(str_repeat('x', 70_000)));
        $this->assertNull($inspector->inspect($this->payload(new FinalizeSeasonvarImportTitleGroup(0))));
        $this->assertNull($inspector->inspect($this->payload(new FinalizeSeasonvarImportTitleGroup(-1))));
        $this->assertNull($inspector->inspect(json_encode([
            'displayName' => $class,
            'data' => [
                'commandName' => FinalizeSeasonvarQueuedImport::class,
                'command' => serialize(new FinalizeSeasonvarImportTitleGroup(123)),
            ],
        ], JSON_THROW_ON_ERROR)));
        $this->assertNull($inspector->inspect(json_encode([
            'displayName' => $class,
            'data' => [
                'commandName' => $class,
                'command' => $nestedCommand,
            ],
        ], JSON_THROW_ON_ERROR)));

        $source = file_get_contents(app_path('Services/Operations/FailedFinalizerPayloadInspector.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('unserialize(', $source);
    }

    private function payload(object $job): string
    {
        return json_encode([
            'displayName' => $job::class,
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
