<?php

declare(strict_types=1);

namespace App\Livewire\ContentRequests;

use App\Actions\ContentRequests\ClarifyContentRequest;
use App\Actions\ContentRequests\SetContentRequestEngagement;
use App\Actions\ContentRequests\UpdateContentRequest;
use App\Actions\ContentRequests\WithdrawContentRequest;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestQuery;
use App\Services\ContentRequests\ContentRequestSeoPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class ContentRequestDetailPage extends Component
{
    #[Locked]
    public int $requestId;

    public string $alternativeTitle = '';

    public string $explanation = '';

    public string $audioLanguage = '';

    public string $subtitleLanguage = '';

    /** @var list<string> */
    public array $sourceLinks = [''];

    public string $clarificationBody = '';

    #[Locked]
    public string $clarificationToken = '';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(ContentRequest $contentRequest): mixed
    {
        Gate::authorize('view', $contentRequest);

        if ($contentRequest->merged_into_id !== null) {
            $canonical = ContentRequest::query()->findOrFail($contentRequest->merged_into_id);
            Gate::authorize('view', $canonical);
            $locale = request()->route('locale');

            return $this->redirectRoute(
                is_string($locale) ? 'localized.requests.show' : 'requests.show',
                is_string($locale) ? ['locale' => $locale, 'contentRequest' => $canonical] : ['contentRequest' => $canonical],
            );
        }

        $this->requestId = $contentRequest->id;
        $this->alternativeTitle = (string) $contentRequest->alternative_title;
        $this->explanation = (string) $contentRequest->explanation;
        $this->audioLanguage = (string) $contentRequest->audio_language;
        $this->subtitleLanguage = (string) $contentRequest->subtitle_language;
        $this->clarificationToken = (string) Str::uuid();

        return null;
    }

    public function setVote(bool $desired, SetContentRequestEngagement $action): void
    {
        $this->perform(fn (User $user) => $action->vote($user, $this->requestId, $desired), __('requests.messages.vote_updated'));
    }

    public function setFollow(bool $desired, SetContentRequestEngagement $action): void
    {
        $this->perform(fn (User $user) => $action->follow($user, $this->requestId, $desired), __('requests.messages.follow_updated'));
    }

    public function save(UpdateContentRequest $action): void
    {
        $request = ContentRequest::query()->findOrFail($this->requestId);
        $this->perform(fn (User $user) => $action->handle($user, $request->id, $request->version, [
            'alternative_title' => $this->alternativeTitle,
            'explanation' => $this->explanation,
            'audio_language' => $this->audioLanguage,
            'subtitle_language' => $this->subtitleLanguage,
            'source_links' => $this->sourceLinks,
        ]), __('requests.messages.updated'));
    }

    public function withdraw(WithdrawContentRequest $action): void
    {
        $this->perform(fn (User $user) => $action->handle($user, $this->requestId), __('requests.messages.withdrawn'));
    }

    public function clarify(ClarifyContentRequest $action): void
    {
        $this->perform(fn (User $user) => $action->reply($user, $this->requestId, $this->clarificationBody, $this->clarificationToken), __('requests.messages.clarification_sent'));
        $this->clarificationBody = '';
        $this->clarificationToken = (string) Str::uuid();
    }

    public function render(ContentRequestQuery $query, ContentRequestSeoPresenter $seo): View
    {
        $request = ContentRequest::query()->findOrFail($this->requestId);
        Gate::authorize('view', $request);
        $viewer = auth()->user();
        $includeClarifications = $viewer instanceof User
            && (Gate::forUser($viewer)->allows('clarify', $request) || Gate::forUser($viewer)->allows('moderate', $request));
        $detail = $query->detail($request, $viewer, $includeClarifications);
        $locale = request()->route('locale');

        return view('livewire.content-requests.detail-page', [
            'request' => $detail,
            'isAuthenticated' => $viewer instanceof User,
            'languageOptions' => collect((array) config('content-requests.language_codes', []))->map(fn (string $code): array => ['value' => $code, 'label' => __('requests.languages.'.$code)])->all(),
            'loginUrl' => route('login'),
        ])->extends('layouts.app', [
            'title' => $request->title,
            'seo' => $seo->detail($request, is_string($locale) ? $locale : null),
        ])->section('content');
    }

    private function perform(callable $operation, string $success): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        try {
            $operation($user);
            $this->statusMessage = $success;
            $this->actionError = null;
        } catch (ContentRequestActionException $exception) {
            $this->statusMessage = null;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (AuthorizationException) {
            $this->statusMessage = null;
            $this->actionError = __('requests.errors.forbidden');
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('requests.errors.action_failed');
        }
    }
}
