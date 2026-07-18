<?php

declare(strict_types=1);

namespace App\Livewire\TechnicalIssues;

use App\Actions\TechnicalIssues\CreateTechnicalIssue;
use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\Enums\HelpFeature;
use App\Enums\TechnicalIssueType;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Services\HelpCenter\HelpContextualLinkService;
use App\Services\TechnicalIssues\TechnicalIssueDuplicateService;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use App\Services\TechnicalIssues\TechnicalIssueTargetResolver;
use App\Services\TechnicalIssues\TechnicalIssueTextSanitizer;
use App\Services\TechnicalIssues\TechnicalIssueTypeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

final class TechnicalIssueFormPage extends Component
{
    use WithFileUploads;

    protected TechnicalIssueTextSanitizer $text;

    #[Locked]
    public string $contextToken = '';

    #[Locked]
    public string $featureCode = 'general';

    #[Locked]
    public string $submissionToken = '';

    #[Locked]
    public string $issueLocale = 'ru';

    #[Locked]
    public bool $contextInvalid = false;

    public string $type = '';

    public string $summary = '';

    public string $expectedBehavior = '';

    public string $actualBehavior = '';

    public string $reproductionSteps = '';

    public ?int $playbackPositionSeconds = null;

    public string $audioLanguage = '';

    public string $subtitleLanguage = '';

    public string $qualityCode = '';

    public bool $diagnosticsConsent = false;

    public string $browserFamily = '';

    public ?int $browserMajor = null;

    public string $operatingSystem = '';

    public string $deviceCategory = '';

    public ?int $viewportWidth = null;

    public ?int $viewportHeight = null;

    public string $timezone = '';

    public ?bool $networkOnline = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $screenshots = [];

    /** @var list<array{public_id: string, number: string, type: string, status: string, url: string}> */
    public array $duplicateCandidates = [];

    public string $duplicateConfidence = 'none';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function boot(TechnicalIssueTextSanitizer $text): void
    {
        $this->text = $text;
    }

    public function mount(TechnicalIssueTargetResolver $targets, TechnicalIssueTypeRegistry $types): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('create', TechnicalIssue::class);
        $this->issueLocale = App::getLocale();
        $context = request()->query('context');
        $feature = request()->query('feature');
        $type = request()->query('type');
        $this->contextToken = is_string($context) ? mb_substr($context, 0, 4096) : '';
        $this->featureCode = is_string($feature) && in_array($feature, config('technical-issues.feature_codes', []), true) ? $feature : 'general';
        $this->type = is_string($type) && in_array($type, TechnicalIssueType::values(), true) ? $type : '';
        $position = filter_var(request()->query('position'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400]]);
        $this->playbackPositionSeconds = is_int($position) ? $position : null;
        $resolvedTarget = null;

        try {
            $target = $targets->resolve($user, $this->contextToken, $this->featureCode);
            $resolvedTarget = $target;
            $this->qualityCode = (string) $target->selectedQualityCode;
            $this->audioLanguage = (string) $target->selectedAudioLanguage;
            $this->subtitleLanguage = (string) $target->selectedSubtitleLanguage;
        } catch (TechnicalIssueActionException $exception) {
            $this->contextInvalid = true;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (Throwable $exception) {
            report($exception);
            $this->contextInvalid = true;
            $this->actionError = __('issues.errors.query_failed');
        }

        $this->submissionToken = (string) Str::uuid();
        $this->restoreDraft();

        $selectedType = TechnicalIssueType::tryFrom($this->type);

        if ($resolvedTarget !== null && $selectedType !== null
            && ! in_array($resolvedTarget->type, $types->rule($selectedType)['targets'], true)) {
            $this->type = '';
        }
    }

    public function hydrate(): void
    {
        $this->restoreLocale();
    }

    public function updatedType(): void
    {
        $this->duplicateCandidates = [];
        $this->duplicateConfidence = 'none';
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if ($property !== 'screenshots' && ! Str::startsWith($property, 'screenshots.')) {
            $this->storeDraft();
        }
    }

    public function removeScreenshot(int $index): void
    {
        if (! array_key_exists($index, $this->screenshots)) {
            return;
        }

        unset($this->screenshots[$index]);
        $this->screenshots = array_values($this->screenshots);
    }

    public function findSimilar(
        TechnicalIssueTargetResolver $targets,
        TechnicalIssueTypeRegistry $types,
        HelpContextualLinkService $helpLinks,
        TechnicalIssueDuplicateService $duplicates,
    ): void {
        $this->perform(function (User $user) use ($targets, $types, $duplicates): void {
            $input = $types->allowlistedInput($this->input());
            $target = $targets->resolve($user, $this->contextToken, $this->featureCode);
            $types->assert($input, $target);
            $result = $duplicates->find($user, $input, $target);
            $this->duplicateConfidence = $result->confidence->value;
            $visibleCandidates = $duplicates->visibleCandidates($user, $result);
            $this->duplicateCandidates = array_map(fn (array $candidate): array => [
                ...$candidate,
                'url' => $this->issueUrl($candidate['public_id']),
            ], $visibleCandidates);
            $this->statusMessage = $result->confidence->value === 'none'
                ? __('issues.states.no_similar')
                : __('issues.states.similar_found');
        });
    }

    public function submit(CreateTechnicalIssue $action): void
    {
        $this->validate([
            'screenshots' => ['array', 'max:'.max(1, (int) config('technical-issues.maximum_attachments', 3))],
            'screenshots.*' => ['file', 'max:'.max(1, (int) config('uploads.max_image_kilobytes', 2048)), 'mimetypes:image/jpeg,image/png,image/webp'],
        ], [
            'screenshots.max' => __('issues.errors.too_many_attachments'),
            'screenshots.*.max' => __('issues.errors.invalid_attachment'),
            'screenshots.*.mimetypes' => __('issues.errors.invalid_attachment'),
        ]);
        $redirect = null;

        $this->perform(function (User $user) use ($action, &$redirect): void {
            $files = $this->uploadedFiles($this->screenshots);
            $result = $action->handle($user, $this->input(), $files);
            $this->screenshots = [];
            session()->forget($this->draftKey());
            $this->submissionToken = (string) Str::uuid();
            $this->statusMessage = $result->existingExactDuplicate
                ? __('issues.states.duplicate')
                : __('issues.states.submitted', ['number' => $result->issue->public_number]);
            session()->flash('technical_issue_status', $this->statusMessage);
            $redirect = $this->issueUrl($result->issue->public_id);
        });

        if (is_string($redirect)) {
            $this->redirect($redirect, navigate: true);
        }
    }

    public function render(
        TechnicalIssueSchema $schema,
        TechnicalIssueTargetResolver $targets,
        TechnicalIssueTypeRegistry $types,
    ): View {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('create', TechnicalIssue::class);
        $schemaReady = $schema->ready();
        $target = null;

        if ($schemaReady && ! $this->contextInvalid) {
            try {
                $target = $targets->resolve($user, $this->contextToken, $this->featureCode);
            } catch (TechnicalIssueActionException $exception) {
                $this->contextInvalid = true;
                $this->actionError = __($exception->translationKey, $exception->replace);
            } catch (Throwable $exception) {
                report($exception);
                $this->contextInvalid = true;
                $this->actionError = __('issues.errors.query_failed');
            }
        }

        $availableTypes = $target === null ? [] : collect(TechnicalIssueType::cases())
            ->filter(fn (TechnicalIssueType $type): bool => in_array($target->type, $types->rule($type)['targets'], true))
            ->map(fn (TechnicalIssueType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'help' => $type->help(),
            ])->values()->all();
        $selected = TechnicalIssueType::tryFrom($this->type);
        $selectedRule = $selected !== null ? $types->rule($selected) : null;

        return view('livewire.technical-issues.form-page', [
            'schemaReady' => $schemaReady,
            'target' => $target,
            'availableTypes' => $availableTypes,
            'selectedType' => $selected,
            'requiresActual' => (bool) ($selectedRule['requires_actual'] ?? false),
            'requiresSteps' => (bool) ($selectedRule['requires_steps'] ?? false),
            'supportsTimestamp' => (bool) ($selectedRule['timestamp'] ?? false),
            'requiresTimestamp' => (bool) ($selectedRule['requires_timestamp'] ?? false),
            'requiresAudio' => (bool) ($selectedRule['requires_audio'] ?? false),
            'requiresSubtitles' => (bool) ($selectedRule['requires_subtitle'] ?? false),
            'requiresQuality' => (bool) ($selectedRule['requires_quality'] ?? false),
            'qualityLocked' => $target?->selectedQualityCode !== null,
            'showAudio' => $selected !== null && in_array($selected, [
                TechnicalIssueType::AudioMissing,
                TechnicalIssueType::AudioLanguageMismatch,
                TechnicalIssueType::AudioSync,
                TechnicalIssueType::TranslationStudioMismatch,
            ], true),
            'showSubtitles' => $selected !== null && in_array($selected, [
                TechnicalIssueType::SubtitlesMissing,
                TechnicalIssueType::SubtitleLanguageMismatch,
                TechnicalIssueType::SubtitleSync,
                TechnicalIssueType::SubtitleTextError,
            ], true),
            'showQuality' => $selected !== null && in_array($selected, [
                TechnicalIssueType::QualityUnavailable,
                TechnicalIssueType::QualityLabelMismatch,
            ], true),
            'maximumAttachments' => max(1, (int) config('technical-issues.maximum_attachments', 3)),
            'helpArticle' => $helpLinks->primary(
                HelpFeature::Tickets,
                'form',
                App::getLocale(),
                is_string(request()->route('locale')) ? request()->route('locale') : null,
            ),
        ])->extends('layouts.app', [
            'title' => __('issues.create.title'),
            'seo' => [
                'title' => __('issues.create.title'),
                'description' => __('issues.create.description'),
                'robots' => 'noindex, nofollow, noarchive',
                'canonical' => $this->createUrl(),
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function input(): TechnicalIssueInput
    {
        $type = TechnicalIssueType::tryFrom($this->type);

        if ($type === null) {
            throw new TechnicalIssueActionException('issues.errors.invalid_type');
        }

        return new TechnicalIssueInput(
            type: $type,
            contextToken: $this->contextToken,
            featureCode: $this->featureCode,
            summary: $this->nullable($this->summary),
            expectedBehavior: $this->nullable($this->expectedBehavior),
            actualBehavior: $this->nullable($this->actualBehavior),
            reproductionSteps: $this->nullable($this->reproductionSteps),
            playbackPositionSeconds: $this->playbackPositionSeconds,
            audioLanguage: $this->nullable($this->audioLanguage),
            subtitleLanguage: $this->nullable($this->subtitleLanguage),
            qualityCode: $this->nullable($this->qualityCode),
            publicErrorCode: null,
            diagnosticsConsent: $this->diagnosticsConsent,
            browserFamily: $this->diagnosticsConsent ? $this->nullable($this->browserFamily) : null,
            browserMajor: $this->diagnosticsConsent ? $this->browserMajor : null,
            operatingSystem: $this->diagnosticsConsent ? $this->nullable($this->operatingSystem) : null,
            deviceCategory: $this->diagnosticsConsent ? $this->nullable($this->deviceCategory) : null,
            viewportWidth: $this->diagnosticsConsent ? $this->viewportWidth : null,
            viewportHeight: $this->diagnosticsConsent ? $this->viewportHeight : null,
            timezone: $this->diagnosticsConsent ? $this->nullable($this->timezone) : null,
            networkOnline: $this->diagnosticsConsent ? $this->networkOnline : null,
            submissionToken: $this->submissionToken,
        );
    }

    private function perform(callable $operation): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $operation($user);
            $this->actionError = null;
        } catch (TechnicalIssueActionException $exception) {
            $this->statusMessage = null;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (AuthorizationException) {
            $this->statusMessage = null;
            $this->actionError = __('issues.errors.forbidden');
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('issues.errors.action_failed');
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function issueUrl(string $publicId): string
    {
        $locale = App::getLocale();

        return in_array($locale, config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.show', ['locale' => $locale, 'technicalIssue' => $publicId])
            : route('issues.show', ['technicalIssue' => $publicId]);
    }

    private function createUrl(): string
    {
        $locale = App::getLocale();

        return in_array($locale, config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.create', ['locale' => $locale])
            : route('issues.create');
    }

    private function restoreLocale(): void
    {
        if (in_array($this->issueLocale, config('technical-issues.supported_locales', []), true)) {
            App::setLocale($this->issueLocale);
        }
    }

    private function storeDraft(): void
    {
        $summary = $this->text->summary($this->summary);
        $expected = $this->text->body($this->expectedBehavior, 4000);
        $actual = $this->text->body($this->actualBehavior, 4000);
        $steps = $this->text->body($this->reproductionSteps, 6000);
        $audioLanguage = $this->draftCode($this->audioLanguage, '/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', 16);
        $subtitleLanguage = $this->draftCode($this->subtitleLanguage, '/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', 16);
        $qualityCode = $this->draftCode($this->qualityCode, '/^(?:\d{3,4}p|auto|source|4k|uhd|hd|sd)$/Di', 24);
        $this->summary = (string) $this->text->display($summary->value);
        $this->expectedBehavior = (string) $this->text->display($expected->value);
        $this->actualBehavior = (string) $this->text->display($actual->value);
        $this->reproductionSteps = (string) $this->text->display($steps->value);
        $this->audioLanguage = $audioLanguage;
        $this->subtitleLanguage = $subtitleLanguage;
        $this->qualityCode = $qualityCode;

        session()->put($this->draftKey(), [
            'type' => in_array($this->type, TechnicalIssueType::values(), true) ? $this->type : '',
            'summary' => $summary->value,
            'expectedBehavior' => $expected->value,
            'actualBehavior' => $actual->value,
            'reproductionSteps' => $steps->value,
            'playbackPositionSeconds' => $this->playbackPositionSeconds,
            'audioLanguage' => $audioLanguage,
            'subtitleLanguage' => $subtitleLanguage,
            'qualityCode' => $qualityCode,
            'diagnosticsConsent' => $this->diagnosticsConsent,
            'submissionToken' => $this->submissionToken,
        ]);
    }

    private function restoreDraft(): void
    {
        $draft = session()->get($this->draftKey());

        if (! is_array($draft)) {
            return;
        }

        foreach (['type', 'audioLanguage', 'subtitleLanguage', 'qualityCode'] as $property) {
            if (is_string($draft[$property] ?? null)) {
                $this->{$property} = $draft[$property];
            }
        }

        foreach (['summary', 'expectedBehavior', 'actualBehavior', 'reproductionSteps'] as $property) {
            if (is_string($draft[$property] ?? null)) {
                $this->{$property} = (string) $this->text->display($draft[$property]);
            }
        }

        $position = filter_var($draft['playbackPositionSeconds'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400]]);
        $this->playbackPositionSeconds = is_int($position) ? $position : $this->playbackPositionSeconds;
        $this->diagnosticsConsent = ($draft['diagnosticsConsent'] ?? false) === true;

        if (is_string($draft['submissionToken'] ?? null) && Str::isUuid($draft['submissionToken'])) {
            $this->submissionToken = $draft['submissionToken'];
        }
    }

    private function draftKey(): string
    {
        return 'technical-issue.draft.'.auth()->id().'.'.hash('sha256', $this->contextToken.'|'.$this->featureCode);
    }

    /**
     * @param  array<int, mixed>  $files
     * @return list<UploadedFile>
     */
    private function uploadedFiles(array $files): array
    {
        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    private function draftCode(string $value, string $pattern, int $maximum): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $maximum && preg_match($pattern, $value) === 1 ? $value : '';
    }
}
