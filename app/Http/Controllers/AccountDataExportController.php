<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\AccountDataExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountDataExportController extends Controller
{
    public function __invoke(Request $request, AccountDataExportService $exports): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $payload = $exports->export($user);

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
