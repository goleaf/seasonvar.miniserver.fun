<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountService;
use App\Services\Auth\AccountSettingsService;
use App\Services\Catalog\UserLibrarySummaryQuery;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Component;

final class ProfilePage extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $emailVerified = false;

    public string $createdAt = '';

    public ?string $status = null;

    public function mount(AccountSettingsService $settings, AccountDateTimeFormatter $dateTimes): void
    {
        $this->fillFromUser($this->user(), $settings, $dateTimes);
    }

    public function saveProfile(
        AccountService $accounts,
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): void {
        $this->resetValidation();
        $this->name = Str::squish($this->name);
        $this->email = Str::lower(Str::squish($this->email));
        $user = $this->user();

        $this->withValidator(function (Validator $validator) use ($user): void {
            $validator->after(function (Validator $validator) use ($user): void {
                if ($this->email !== '' && User::query()
                    ->whereKeyNot($user->getKey())
                    ->whereRaw('lower(email) = ?', [$this->email])
                    ->exists()) {
                    $validator->errors()->add('email', __('settings.profile_page.validation.email_unique'));
                }
            });
        });

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
        ], [
            'name.required' => __('settings.profile_page.validation.name_required'),
            'name.min' => __('settings.profile_page.validation.name_min'),
            'name.max' => __('settings.profile_page.validation.name_max'),
            'name.not_regex' => __('settings.profile_page.validation.name_controls'),
            'email.required' => __('settings.profile_page.validation.email_required'),
            'email.email' => __('settings.profile_page.validation.email_format'),
            'email.unique' => __('settings.profile_page.validation.email_unique'),
        ]);

        $emailChanged = Str::lower($user->email) !== $validated['email'];
        $updated = $accounts->updateProfile($user, $validated);

        $this->fillFromUser($updated, $settings, $dateTimes);
        $this->status = $emailChanged
            ? __('settings.profile_page.updated_verify_email')
            : __('settings.profile_page.updated');
    }

    public function render(UserLibrarySummaryQuery $summaries, CatalogCollectionQuery $collections): View
    {
        return view('livewire.profile.profile-page', [
            'librarySummary' => $summaries->get($this->user()),
            'collectionSummary' => $collections->ownerCounts($this->user()),
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

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
