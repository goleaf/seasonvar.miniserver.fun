<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Profiles\UserProfileService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class AccountDataExportResponder
{
    public function __construct(
        private AccountDataExportService $exports,
        private UserProfileService $profiles,
    ) {}

    public function response(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $payload = $this->exports->export($user);
        $payload['profile'] = $this->profiles->export($user);

        return response()->streamDownload(
            static function () use ($payload): void {
                echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            'seasonvar-account-'.$user->public_id.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
