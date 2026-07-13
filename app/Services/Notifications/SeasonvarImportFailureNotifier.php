<?php

namespace App\Services\Notifications;

use App\Notifications\SeasonvarImportFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SeasonvarImportFailureNotifier
{
    public function notify(
        ?string $argument,
        bool $force,
        bool $discover,
        ?Throwable $exception,
    ): void {
        $mailTo = $this->configuredRecipient();

        if ($mailTo === null) {
            return;
        }

        Notification::route('mail', $mailTo)
            ->notify((new SeasonvarImportFailed(
                targeted: $argument !== null && $argument !== '',
                force: $force,
                discover: $discover,
                exceptionClass: $exception ? get_class($exception) : null,
            ))->afterCommit());
    }

    /**
     * @return array<string, string>|string|null
     */
    private function configuredRecipient(): array|string|null
    {
        $address = config('notifications.seasonvar_import_failed.mail_to');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Seasonvar import failure notification recipient is not a valid email address.');

            return null;
        }

        $name = config('notifications.seasonvar_import_failed.mail_to_name');

        if (is_string($name) && trim($name) !== '') {
            return [$address => trim($name)];
        }

        return $address;
    }
}
