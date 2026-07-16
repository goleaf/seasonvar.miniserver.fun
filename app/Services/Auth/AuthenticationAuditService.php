<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

final class AuthenticationAuditService
{
    public function __construct(
        private readonly AuthenticationFingerprint $fingerprints,
        private readonly LogManager $logs,
        private readonly Request $request,
    ) {}

    public function record(
        AuthenticationEvent $event,
        ?User $user = null,
        ?string $email = null,
    ): void {
        $context = [
            'event' => $event->value,
            'user_id' => $user?->getAuthIdentifier(),
            'email_fingerprint' => $email !== null && $email !== ''
                ? $this->fingerprints->email($email)
                : null,
            'network_fingerprint' => $this->fingerprints->network($this->request->ip()),
            'request_id' => $this->request->attributes->get('api_request_id'),
        ];

        try {
            $logger = is_array(config('logging.channels.authentication'))
                ? $this->logs->channel('authentication')
                : $this->logs->channel();

            $logger->info('authentication_event', array_filter(
                $context,
                static fn (mixed $value): bool => $value !== null,
            ));
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // Audit transport failures must never change an authentication decision.
            }
        }
    }
}
