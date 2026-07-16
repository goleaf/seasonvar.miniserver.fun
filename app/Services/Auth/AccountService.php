<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationCacheInvalidator;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Comments\CommentAccountService;
use App\Services\ContentRequests\ContentRequestAccountService;
use App\Services\Profiles\UserProfileMediaService;
use App\Services\Reviews\ReviewAccountService;
use App\Support\UserPlainText;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly ContentRequestAccountService $contentRequests,
        private readonly UserProfileMediaService $profileMedia,
        private readonly CatalogRecommendationCacheInvalidator $recommendationCache,
        private readonly AuthenticationAuditService $audit,
    ) {}

    /** @param array{name?: string, email?: string} $data */
    public function updateProfile(User $user, array $data, ?string $currentPassword = null): User
    {
        $data = array_intersect_key($data, array_flip(['name', 'email']));

        if (array_key_exists('name', $data)) {
            $data['name'] = UserPlainText::name($data['name']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = NormalizedEmail::value($data['email']);
        }

        $emailChanged = false;
        $nameChanged = false;

        try {
            DB::transaction(function () use (
                $user,
                $data,
                $currentPassword,
                &$emailChanged,
                &$nameChanged,
            ): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $oldEmail = NormalizedEmail::value((string) $lockedUser->email);
                $emailChanged = array_key_exists('email', $data)
                    && $oldEmail !== $data['email'];
                $nameChanged = array_key_exists('name', $data)
                    && $lockedUser->name !== $data['name'];

                if ($emailChanged) {
                    if (User::query()
                        ->whereKeyNot($lockedUser->getKey())
                        ->whereEmailIdentity($data['email'])
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'email' => [__('auth.validation.email_unique')],
                        ]);
                    }

                    $this->confirmEmailChange($lockedUser, $currentPassword);
                    $lockedUser->email_verified_at = null;
                    $lockedUser->remember_token = Str::random(60);
                    DB::table('password_reset_tokens')
                        ->whereRaw('lower(email) in (?, ?)', [$oldEmail, $data['email']])
                        ->delete();
                }

                $lockedUser->fill($data);
                $lockedUser->save();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => [__('auth.validation.email_unique')],
            ]);
        }

        $user->refresh();

        if ($emailChanged) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $exception) {
                report($exception);
            }

            $this->audit->record(AuthenticationEvent::EmailChanged, $user, $data['email']);
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

        DB::transaction(function () use ($user, $current, $new, $currentTokenId): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! Hash::check($current, $lockedUser->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'current_password' => [__('settings.security_page.current_password_invalid')],
                ]);
            }

            $lockedUser->forceFill([
                'password' => Hash::make($new),
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('password_reset_tokens')
                ->whereRaw('lower(email) = ?', [NormalizedEmail::value((string) $lockedUser->email)])
                ->delete();

            $tokens = $lockedUser->tokens();

            if ($currentTokenId !== null) {
                $tokens->where('id', '!=', $currentTokenId);
            }

            $tokens->delete();
        }, attempts: 3);

        $user->refresh();
        RateLimiter::clear($rateKey);
        $this->audit->record(AuthenticationEvent::PasswordChanged, $user, $user->email);
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

        DB::transaction(function () use ($user, $password): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! Hash::check($password, $lockedUser->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'password' => [__('settings.security_page.deletion_password_invalid')],
                ]);
            }

            $this->collections->purgeOwned($lockedUser);
            $this->comments->prepareForDeletion($lockedUser);
            $this->reviews->prepareForDeletion($lockedUser);
            $this->contentRequests->prepareForDeletion($lockedUser);
            $profile = $lockedUser->profile()->first();

            if ($profile !== null) {
                $this->profileMedia->purge($profile);
            }

            $lockedUser->tokens()->delete();
            DB::table('password_reset_tokens')
                ->whereRaw('lower(email) = ?', [NormalizedEmail::value((string) $lockedUser->email)])
                ->delete();
            DB::table('sessions')->where('user_id', $lockedUser->getKey())->delete();
            $lockedUser->deleteOrFail();
        }, attempts: 3);

        $this->recommendationCache->publicSignalsChanged('account-deleted');
        $this->audit->record(AuthenticationEvent::AccountDeleted, $user, $user->email);
        RateLimiter::clear($rateKey);
    }

    private function confirmEmailChange(User $user, ?string $currentPassword): void
    {
        $rateKey = 'account-email-change:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 6)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.security_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 300);

        if (! is_string($currentPassword) || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.validation.current_password_invalid')],
            ]);
        }

        RateLimiter::clear($rateKey);
    }
}
