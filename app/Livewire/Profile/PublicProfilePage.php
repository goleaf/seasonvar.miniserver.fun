<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Comments\SetUserBlock;
use App\Actions\Comments\SetUserMute;
use App\Enums\CatalogWatchStatus;
use App\Enums\UserProfileReportCategory;
use App\Exceptions\Comments\CommentActionException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Profiles\PublicUserProfilePresenter;
use App\Services\Profiles\PublicUserProfileQuery;
use App\Services\Profiles\UserProfileReportService;
use App\Services\Profiles\UserProfileResolver;
use App\Services\Profiles\UserProfileSeoPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class PublicProfilePage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Locked]
    public string $username = '';

    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $tab = 'overview';

    public bool $reportOpen = false;

    public string $reportCategory = 'other';

    public string $reportDetails = '';

    public ?string $notice = null;

    public ?string $actionError = null;

    #[Locked]
    public bool $loadFailed = false;

    public function mount(string $username, UserProfileResolver $profiles, ?string $locale = null): void
    {
        try {
            $resolved = $profiles->byUsername($username);
            Gate::authorize('view', $resolved->profile);
            $this->username = $resolved->profile->username;
        } catch (QueryException $exception) {
            report($exception);
            $this->username = mb_strtolower($username);
            $this->loadFailed = true;

            return;
        }

        if ($resolved->fromHistory || $username !== $this->username) {
            $this->redirectRoute('users.show', $this->canonicalRedirectParameters(), navigate: false);
        }
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, $this->allowedTabs(), true)) {
            $this->tab = 'overview';
        }

        $this->resetPage('reviewsPage');
        $this->resetPage('commentsPage');
        $this->resetPage('profileCollectionsPage');
        $this->resetPage('watchingPage');
        $this->resetPage('completedPage');
    }

    public function block(SetUserBlock $blocks, UserProfileResolver $profiles): void
    {
        $viewer = $this->viewer(required: true);
        $profile = $this->profile($profiles);

        try {
            $blocks->handle($viewer, (int) $profile->user_id, true);
            session()->flash('status', __('profiles.actions.blocked'));
            $this->redirectRoute('home', navigate: false);
        } catch (CommentActionException $exception) {
            $this->actionError = $exception->localizedMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('profiles.errors.action_failed');
        }
    }

    public function toggleMute(SetUserMute $mutes, UserProfileResolver $profiles): void
    {
        $viewer = $this->viewer(required: true);
        $profile = $this->profile($profiles);
        $isMuted = $viewer->mutedUsers()->where('muted_id', $profile->user_id)->exists();

        try {
            $mutes->handle($viewer, (int) $profile->user_id, ! $isMuted);
            $this->notice = $isMuted ? __('profiles.actions.unmuted') : __('profiles.actions.muted');
            $this->actionError = null;
        } catch (CommentActionException $exception) {
            $this->actionError = $exception->localizedMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('profiles.errors.action_failed');
        }
    }

    public function openReport(): void
    {
        $this->reportOpen = true;
        $this->reportCategory = UserProfileReportCategory::Other->value;
        $this->reportDetails = '';
        $this->resetValidation();
    }

    public function closeReport(): void
    {
        $this->reportOpen = false;
        $this->reportDetails = '';
        $this->resetValidation();
    }

    public function submitReport(UserProfileReportService $reports, UserProfileResolver $profiles): void
    {
        $validated = $this->validate([
            'reportCategory' => ['required', Rule::enum(UserProfileReportCategory::class)],
            'reportDetails' => ['nullable', 'string', 'max:'.max(1, (int) config('user-profiles.reports.maximum_details_length', 1500)), 'not_regex:/(?!\n|\t)[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
        ], [
            'reportCategory.required' => __('profiles.validation.report_category'),
            'reportCategory.enum' => __('profiles.validation.report_category'),
            'reportDetails.max' => __('profiles.validation.report_details_max'),
            'reportDetails.not_regex' => __('profiles.validation.report_details_controls'),
        ]);
        $viewer = $this->viewer(required: true);
        $profile = $this->profile($profiles);

        try {
            $reports->report(
                $viewer,
                $profile,
                UserProfileReportCategory::from($validated['reportCategory']),
                $validated['reportDetails'] !== '' ? $validated['reportDetails'] : null,
            );
            $this->closeReport();
            $this->notice = __('profiles.reports.submitted');
            $this->actionError = null;
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);
            $this->actionError = __('profiles.errors.action_failed');
        }
    }

    public function render(
        UserProfileResolver $profiles,
        PublicUserProfilePresenter $presenter,
        PublicUserProfileQuery $query,
        UserProfileSeoPresenter $seo,
    ): View {
        if ($this->loadFailed) {
            return $this->unavailableView();
        }

        try {
            return $this->renderProfile($profiles, $presenter, $query, $seo);
        } catch (QueryException $exception) {
            report($exception);
            $this->loadFailed = true;

            return $this->unavailableView();
        }
    }

    private function renderProfile(
        UserProfileResolver $profiles,
        PublicUserProfilePresenter $presenter,
        PublicUserProfileQuery $query,
        UserProfileSeoPresenter $seo,
    ): View {
        $profile = $this->profile($profiles);
        $viewer = $this->viewer();
        $data = $presenter->present($profile, $viewer);
        $this->normalizeTab($data->sections);
        $items = match ($this->tab) {
            'reviews' => $query->reviews($profile, $viewer),
            'comments' => $query->comments($profile, $viewer),
            'collections' => $query->collections($profile),
            'watching' => $query->watchList($profile, $viewer, CatalogWatchStatus::Watching),
            'completed' => $query->watchList($profile, $viewer, CatalogWatchStatus::Completed),
            default => null,
        };
        $localizedAlias = request()->routeIs('localized.users.show');
        $statefulVariant = $this->tab !== 'overview'
            || request()->query->has('page')
            || request()->query->has('reviewsPage')
            || request()->query->has('commentsPage');

        return view('livewire.profile.public-profile-page', [
            'profileData' => $data,
            'items' => $items,
            'hasPublicSections' => collect($data->sections)->except('activity')->contains(true),
            'reportMaximumLength' => max(1, (int) config('user-profiles.reports.maximum_details_length', 1500)),
            'reportCategories' => collect(UserProfileReportCategory::cases())->map(fn (UserProfileReportCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ])->all(),
        ])->extends('layouts.app', [
            'title' => __('profiles.seo.title', ['name' => $data->displayName, 'username' => $data->username]),
            'seo' => $seo->present($profile, $data, $localizedAlias, $statefulVariant),
        ])->section('content');
    }

    /** @param array<string, bool> $sections */
    private function normalizeTab(array $sections): void
    {
        $requiresSection = [
            'reviews' => 'reviews',
            'comments' => 'comments',
            'collections' => 'collections',
            'watching' => 'watching',
            'completed' => 'completed',
        ];

        if (! in_array($this->tab, $this->allowedTabs(), true)
            || (isset($requiresSection[$this->tab]) && ! ($sections[$requiresSection[$this->tab]] ?? false))) {
            $this->tab = 'overview';
        }
    }

    /** @return list<string> */
    private function allowedTabs(): array
    {
        return ['overview', 'reviews', 'comments', 'collections', 'watching', 'completed'];
    }

    /** @return array<string, int|string> */
    private function canonicalRedirectParameters(): array
    {
        $parameters = ['username' => $this->username];

        if ($this->tab === 'overview' || ! in_array($this->tab, $this->allowedTabs(), true)) {
            return $parameters;
        }

        $parameters['tab'] = $this->tab;
        $pageName = match ($this->tab) {
            'reviews' => 'reviewsPage',
            'comments' => 'commentsPage',
            'collections' => 'profileCollectionsPage',
            'watching' => 'watchingPage',
            'completed' => 'completedPage',
            default => null,
        };
        $page = $pageName !== null ? request()->query($pageName) : null;

        if ($pageName !== null && is_string($page) && ctype_digit($page) && (int) $page > 1) {
            $parameters[$pageName] = (int) $page;
        }

        return $parameters;
    }

    private function unavailableView(): View
    {
        return view('livewire.profile.public-profile-unavailable')
            ->extends('layouts.app', [
                'title' => __('profiles.errors.unavailable'),
                'seo' => [
                    'title' => __('profiles.errors.unavailable'),
                    'description' => __('profiles.errors.load_failed'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => url()->current(),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }

    private function profile(UserProfileResolver $profiles): UserProfile
    {
        $resolved = $profiles->byUsername($this->username);
        Gate::authorize('view', $resolved->profile);

        return $resolved->profile;
    }

    private function viewer(bool $required = false): ?User
    {
        $viewer = auth()->user();

        if ($required) {
            abort_unless($viewer instanceof User, 403);
        }

        return $viewer instanceof User ? $viewer : null;
    }
}
