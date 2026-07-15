<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Comments\CommentAccountService;
use App\Services\Reviews\ReviewAccountService;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountService
{
    public function __construct(
        private readonly CatalogCollectionAccountService $collections,
        private readonly CommentAccountService $comments,
        private readonly ReviewAccountService $reviews,
    ) {}

    /** @param array{name?: string, email?: string} $data */
    public function updateProfile(User $user, array $data): User
    {
        $data = array_intersect_key($data, array_flip(['name', 'email']));

        if (array_key_exists('name', $data)) {
            $data['name'] = UserPlainText::name($data['name']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = Str::lower(Str::squish($data['email']));
        }

        $oldEmail = Str::lower(Str::squish((string) $user->email));
        $emailChanged = array_key_exists('email', $data)
            && $oldEmail !== $data['email'];
        $nameChanged = array_key_exists('name', $data)
            && $user->name !== $data['name'];

        DB::transaction(function () use ($user, $data, $oldEmail, $emailChanged): void {
            $user->fill($data);

            if ($emailChanged) {
                $user->email_verified_at = null;
                DB::table('password_reset_tokens')
                    ->whereRaw('lower(email) in (?, ?)', [$oldEmail, $data['email']])
                    ->delete();
            }

            $user->save();
        }, attempts: 3);

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        if ($nameChanged) {
            $this->collections->ownerIdentityChanged($user);
            $this->comments->authorIdentityChanged($user);
            $this->reviews->authorIdentityChanged($user);
        }

        return $user->refresh();
    }

    public function updatePassword(User $user, string $current, string $new, ?int $currentTokenId): void
    {
        $rateKey = 'account-password-change:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 6)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.security_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 300);

        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.current_password_invalid')],
            ]);
        }

        DB::transaction(function () use ($user, $new, $currentTokenId): void {
            $user->forceFill([
                'password' => Hash::make($new),
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('password_reset_tokens')
                ->whereRaw('lower(email) = ?', [Str::lower((string) $user->email)])
                ->delete();

            $tokens = $user->tokens();

            if ($currentTokenId !== null) {
                $tokens->where('id', '!=', $currentTokenId);
            }

            $tokens->delete();
        }, attempts: 3);

        RateLimiter::clear($rateKey);
    }

    public function delete(User $user, string $password): void
    {
        $rateKey = 'account-delete:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 4)) {
            throw ValidationException::withMessages([
                'password' => [__('settings.security_page.security_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 300);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('settings.security_page.deletion_password_invalid')],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->collections->purgeOwned($lockedUser);
            $this->comments->prepareForDeletion($lockedUser);
            $this->reviews->prepareForDeletion($lockedUser);
            $lockedUser->tokens()->delete();
            DB::table('password_reset_tokens')
                ->whereRaw('lower(email) = ?', [Str::lower((string) $lockedUser->email)])
                ->delete();
            DB::table('sessions')->where('user_id', $lockedUser->getKey())->delete();
            $lockedUser->deleteOrFail();
        }, attempts: 3);

        RateLimiter::clear($rateKey);
    }
}
