<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

final class AuthenticationAuditService
{
    public function __construct(
        private readonly AuthenticationFingerprint $fingerprints,
        private readonly LoggerInterface $logger,
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
            'request_id' => $this->request->attributes->get('request_id'),
        ];

        $this->logger->info('authentication_event', array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null,
        ));
    }
}
