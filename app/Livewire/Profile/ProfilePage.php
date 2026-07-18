<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountService;
use App\Services\Auth\AccountSettingsService;
use App\Services\Catalog\UserLibrarySummaryQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Profiles\UserProfileMediaService;
use App\Services\Profiles\UserProfileService;
use App\ValueObjects\NormalizedEmail;
use App\ValueObjects\ProfileUsername;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProfilePage extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public bool $emailVerified = false;

    public string $createdAt = '';

    public ?string $status = null;

    public ?string $profileActionError = null;

    public string $username = '';

    public string $profilePassword = '';

    public string $biography = '';

    public string $profileVisibility = 'private';

    /** @var array<string, string> */
    public array $sectionVisibility = [];

    public ?TemporaryUploadedFile $avatarUpload = null;

    public ?TemporaryUploadedFile $coverUpload = null;

    public function mount(
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
        UserProfileService $profiles,
    ): void {
        $this->fillFromUser($this->user(), $settings, $dateTimes);
        $this->fillFromProfile($profiles->forUser($this->user()));
    }

    public function saveProfile(
        AccountService $accounts,
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): void {
        $this->beginProfileAction();
        $this->resetValidation();
        $this->name = Str::squish($this->name);
        $this->email = NormalizedEmail::value($this->email);
        $user = $this->user();

        $this->withValidator(function (Validator $validator) use ($user): void {
            $validator->after(function (Validator $validator) use ($user): void {
                if ($this->email !== '' && User::query()
                    ->whereKeyNot($user->getKey())
                    ->whereEmailIdentity($this->email)
                    ->exists()) {
                    $validator->errors()->add('email', __('settings.profile_page.validation.email_unique'));
                }
            });
        });

        try {
            $validated = $this->validate([
                'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
                'email' => [
                    'required',
                    'string',
                    'lowercase',
                    'email:rfc',
                    'max:255',
                    Rule::unique(User::class, 'email')->ignore($user),
                ],
                'currentPassword' => ['nullable', 'string', 'max:255'],
            ], [
                'name.required' => __('settings.profile_page.validation.name_required'),
                'name.min' => __('settings.profile_page.validation.name_min'),
                'name.max' => __('settings.profile_page.validation.name_max'),
                'name.not_regex' => __('settings.profile_page.validation.name_controls'),
                'email.required' => __('settings.profile_page.validation.email_required'),
                'email.email' => __('settings.profile_page.validation.email_format'),
                'email.max' => __('auth.validation.email_max'),
                'email.unique' => __('settings.profile_page.validation.email_unique'),
                'currentPassword.max' => __('auth.validation.password_max'),
            ]);
        } catch (ValidationException $exception) {
            $this->reset('currentPassword');

            throw $exception;
        }

        $emailChanged = NormalizedEmail::value($user->email) !== $validated['email'];

        try {
            $updated = $accounts->updateProfile($user, [
                'name' => $validated['name'],
                'email' => $validated['email'],
            ], $this->currentPassword);
        } catch (ValidationException $exception) {
            $this->reset('currentPassword');

            if (isset($exception->errors()['email'])) {
                $this->addError('email', $exception->errors()['email'][0]);
            } else {
                $this->addError(
                    'currentPassword',
                    $exception->errors()['current_password'][0] ?? __('auth.validation.current_password_invalid'),
                );
            }

            return;
        } catch (Throwable $exception) {
            $this->reset('currentPassword');
            $this->recordProfileFailure($exception);

            return;
        }

        $this->reset('currentPassword');
        $this->fillFromUser($updated, $settings, $dateTimes);

        $this->status = $emailChanged
            ? __('settings.profile_page.updated_verify_email')
            : __('settings.profile_page.updated');
    }

    public function savePublicDetails(UserProfileService $profiles): void
    {
        $this->beginProfileAction();
        $validated = $this->validate([
            'biography' => ['nullable', 'string', 'max:'.max(1, (int) config('user-profiles.biography_maximum_length', 1200)), 'not_regex:/(?!\n|\t)[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
        ], [
            'biography.max' => __('profiles.validation.biography_max'),
            'biography.not_regex' => __('profiles.validation.biography_controls'),
        ]);
        try {
            $profile = $profiles->updateDetails($this->user(), $profiles->forUser($this->user()), [
                'biography' => $validated['biography'] !== '' ? $validated['biography'] : null,
            ]);
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.updated');
    }

    public function saveUsername(UserProfileService $profiles): void
    {
        $this->beginProfileAction();
        $this->username = ProfileUsername::normalize($this->username);
        $validated = $this->validate([
            'username' => [
                'required',
                'string',
                'min:'.max(1, (int) config('user-profiles.username.minimum_length', 3)),
                'max:'.max(3, (int) config('user-profiles.username.maximum_length', 32)),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::notIn((array) config('user-profiles.username.reserved', [])),
            ],
            'profilePassword' => ['required', 'string', 'max:255'],
        ], [
            'username.required' => __('profiles.validation.username_required'),
            'username.min' => __('profiles.validation.username_format'),
            'username.max' => __('profiles.validation.username_format'),
            'username.regex' => __('profiles.validation.username_format'),
            'username.not_in' => __('profiles.validation.username_reserved'),
            'profilePassword.required' => __('profiles.validation.current_password'),
        ]);

        try {
            $profile = $profiles->changeUsername(
                $this->user(),
                $profiles->forUser($this->user()),
                $validated['username'],
                $validated['profilePassword'],
            );
        } catch (ValidationException $exception) {
            $this->reset('profilePassword');

            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'profile_password' ? 'profilePassword' : $field, $messages[0]);
            }

            return;
        } catch (Throwable $exception) {
            $this->reset('profilePassword');
            $this->recordProfileFailure($exception);

            return;
        }

        $this->reset('profilePassword');
        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.username_updated');
    }

    public function saveProfilePrivacy(UserProfileService $profiles): void
    {
        $this->beginProfileAction();
        $visibilityValues = ['public', 'private'];
        $rules = ['profileVisibility' => ['required', Rule::in($visibilityValues)]];

        foreach ($this->profileSections() as $section) {
            $rules['sectionVisibility.'.$section] = ['required', Rule::in($visibilityValues)];
        }

        $validated = $this->validate($rules, [
            'profileVisibility.in' => __('profiles.validation.visibility'),
            'sectionVisibility.*.in' => __('profiles.validation.visibility'),
        ]);
        $visibility = ['profile_visibility' => $validated['profileVisibility']];

        foreach ($this->profileSections() as $section) {
            $visibility[$section.'_visibility'] = $validated['sectionVisibility'][$section];
        }

        try {
            $profile = $profiles->updatePrivacy($this->user(), $profiles->forUser($this->user()), $visibility);
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.privacy_updated');
    }

    public function saveAvatar(UserProfileMediaService $media, UserProfileService $profiles): void
    {
        $this->beginProfileAction();
        $this->validate([
            'avatarUpload' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.max(1, (int) config('user-profiles.uploads.avatar_maximum_kilobytes', 3072)),
                'dimensions:min_width=128,min_height=128,max_width=4096,max_height=4096',
            ],
        ], ['avatarUpload.*' => __('profiles.validation.avatar')]);
        try {
            $profile = $media->replace($this->user(), $profiles->forUser($this->user()), 'avatar', $this->avatarUpload);
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->reset('avatarUpload');
        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.media_updated');
    }

    public function saveCover(UserProfileMediaService $media, UserProfileService $profiles): void
    {
        $this->beginProfileAction();
        $this->validate([
            'coverUpload' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.max(1, (int) config('user-profiles.uploads.cover_maximum_kilobytes', 6144)),
                'dimensions:min_width=640,min_height=180,max_width=6000,max_height=3000',
            ],
        ], ['coverUpload.*' => __('profiles.validation.cover')]);
        try {
            $profile = $media->replace($this->user(), $profiles->forUser($this->user()), 'cover', $this->coverUpload);
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->reset('coverUpload');
        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.media_updated');
    }

    public function removeAvatar(UserProfileMediaService $media, UserProfileService $profiles): void
    {
        $this->beginProfileAction();

        try {
            $profile = $media->remove($this->user(), $profiles->forUser($this->user()), 'avatar');
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.media_removed');
    }

    public function removeCover(UserProfileMediaService $media, UserProfileService $profiles): void
    {
        $this->beginProfileAction();

        try {
            $profile = $media->remove($this->user(), $profiles->forUser($this->user()), 'cover');
        } catch (Throwable $exception) {
            $this->recordProfileFailure($exception);

            return;
        }

        $this->fillFromProfile($profile);
        $this->status = __('profiles.settings.media_removed');
    }

    public function render(
        UserLibrarySummaryQuery $summaries,
        CatalogCollectionQuery $collections,
        UserProfileService $profiles,
        UserProfileMediaService $media,
    ): View {
        $profile = $profiles->forUser($this->user());

        return view('livewire.profile.profile-page', [
            'librarySummary' => $summaries->get($this->user()),
            'collectionSummary' => $collections->ownerCounts($this->user()),
            'publicProfileUrl' => route('users.show', ['username' => $profile->username]),
            'avatarUrl' => $media->url($profile, 'avatar'),
            'coverUrl' => $media->url($profile, 'cover'),
            'biographyMaximumLength' => max(1, (int) config('user-profiles.biography_maximum_length', 1200)),
            'profileVisibilityOptions' => collect(['public', 'private'])->map(fn (string $value): array => [
                'value' => $value,
                'label' => __('profiles.visibility.'.$value),
            ])->all(),
            'profileSections' => collect($this->profileSections())->map(fn (string $section): array => [
                'key' => $section,
                'label' => __('profiles.settings.sections.'.$section),
            ])->all(),
        ])
            ->extends('layouts.app', [
                'title' => __('settings.profile_page.title'),
                'seo' => [
                    'title' => __('settings.profile_page.title'),
                    'description' => __('settings.profile_page.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('profile.show'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }

    private function fillFromUser(
        User $user,
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): void {
        $accountSettings = $settings->resolve($user);
        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailVerified = $user->hasVerifiedEmail();
        $this->createdAt = $user->created_at !== null
            ? $dateTimes->value($user->created_at, $accountSettings->locale, $accountSettings->timezone)
            : '';
    }

    private function fillFromProfile(UserProfile $profile): void
    {
        $this->username = $profile->username;
        $this->biography = $profile->biography ?? '';
        $this->profileVisibility = $profile->profile_visibility->value;
        $this->sectionVisibility = collect($this->profileSections())->mapWithKeys(fn (string $section): array => [
            $section => $profile->getAttribute($section.'_visibility')->value,
        ])->all();
    }

    private function beginProfileAction(): void
    {
        $this->status = null;
        $this->profileActionError = null;
    }

    private function recordProfileFailure(Throwable $exception): void
    {
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429) {
            $this->profileActionError = __('profiles.errors.rate_limited');

            return;
        }

        report($exception);
        $this->profileActionError = __('profiles.errors.action_failed');
    }

    /** @return list<string> */
    private function profileSections(): array
    {
        return ['biography', 'member_since', 'collections', 'reviews', 'comments', 'watching', 'completed'];
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
