<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class HelpActorKey
{
    public function for(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return hash_hmac('sha256', 'user:'.$user->getKey(), $this->secret());
        }

        $session = $request->session();
        $identifier = $session->get('help_actor_id');

        if (! is_string($identifier) || ! Str::isUuid($identifier)) {
            $identifier = (string) Str::uuid();
            $session->put('help_actor_id', $identifier);
        }

        return hash_hmac('sha256', 'session:'.$identifier, $this->secret());
    }

    private function secret(): string
    {
        return hash('sha256', (string) config('app.key'));
    }
}
