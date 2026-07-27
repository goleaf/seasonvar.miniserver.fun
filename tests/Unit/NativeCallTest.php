<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NativeCall;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class NativeCallTest extends TestCase
{
    public function test_it_returns_the_native_callback_result(): void
    {
        $this->assertSame(
            'result',
            NativeCall::withWarningsAsExceptions(static fn (): string => 'result'),
        );
    }

    public function test_it_converts_a_native_warning_to_an_error_exception(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('native warning');

        NativeCall::withWarningsAsExceptions(
            static function (): void {
                trigger_error('native warning', E_USER_WARNING);
            },
        );
    }

    public function test_it_restores_the_previous_error_handler_after_a_warning(): void
    {
        $handledByPreviousHandler = false;

        set_error_handler(
            static function () use (&$handledByPreviousHandler): bool {
                $handledByPreviousHandler = true;

                return true;
            },
            E_USER_WARNING,
        );

        try {
            try {
                NativeCall::withWarningsAsExceptions(
                    static function (): void {
                        trigger_error('inside native boundary', E_USER_WARNING);
                    },
                );
            } catch (ErrorException) {
                // The assertion is that the previous handler is restored below.
            }

            trigger_error('after native boundary', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($handledByPreviousHandler);
    }
}
