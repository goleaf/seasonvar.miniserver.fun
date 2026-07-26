<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ContentRequests\ChangeContentRequestStatus;
use App\Actions\ContentRequests\CreateContentRequest;
use App\Actions\ContentRequests\SetContentRequestEngagement;
use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentCorrectionField;
use App\Enums\ContentCorrectionReason;
use App\Enums\ContentRequestNotificationType;
use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Enums\HelpEscalationType;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Livewire\ContentRequests\ContentRequestFormPage;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\HelpArticle;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\User;
use App\Notifications\ContentRequestActivityNotification;
use App\Services\ContentRequests\CatalogCorrectionTargetResolver;
use App\Services\ContentRequests\ContentRequestInputFactory;
use App\Services\ContentRequests\ContentRequestNotificationQuery;
use App\Services\ContentRequests\ContentRequestNotificationService;
use App\Services\HelpCenter\HelpEscalationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogFieldCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_keeps_the_administrative_correction_target_and_reason(): void
    {
        self::assertTrue(Schema::hasColumns('content_requests', [
            'correction_target_key',
            'correction_reason',
        ]));
    }

    public function test_public_title_and_player_remove_every_correction_control_for_users_and_administrators(): void
    {
        [$title, , $episode] = $this->catalogFixture();
        $users = [User::factory()->create(), $this->administrator()];

        foreach ($users as $user) {
            $response = $this->actingAs($user)->get(route('titles.show', [
                'catalogTitle' => $title,
                'season' => $episode->season_id,
                'episode' => $episode->id,
            ]));

            $response
                ->assertOk()
                ->assertDontSee('data-correction-field=', false)
                ->assertDontSee('Исправить данные')
                ->assertDontSee('type=metadata_correction', false)
                ->assertDontSee('type=episode_list_correction', false);
        }
    }

    public function test_public_request_form_excludes_administrative_correction_types(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ContentRequestFormPage::class)
            ->assertOk()
            ->assertDontSeeText(ContentRequestType::MetadataCorrection->label())
            ->assertDontSeeText(ContentRequestType::EpisodeListCorrection->label());
    }

    public function test_verified_non_administrator_cannot_open_a_direct_correction_form(): void
    {
        [$title, $tag] = $this->catalogFixture();
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams([
            'type' => ContentRequestType::MetadataCorrection->value,
            'catalog_title_id' => $title->id,
            'field' => ContentCorrectionField::Tag->value,
            'target' => $tag->id,
        ])->test(ContentRequestFormPage::class)->assertForbidden();
    }

    public function test_correction_form_still_requires_authentication(): void
    {
        [$title, $tag] = $this->catalogFixture();

        $this->get(route('requests.create', [
            'type' => ContentRequestType::MetadataCorrection->value,
            'catalog_title_id' => $title->id,
            'field' => ContentCorrectionField::Tag->value,
            'target' => $tag->id,
        ]))->assertRedirect(route('login'));
    }

    public function test_administrator_can_open_a_server_resolved_correction_form(): void
    {
        [$title, $tag] = $this->catalogFixture();
        $this->actingAs($this->administrator());

        Livewire::withQueryParams([
            'type' => ContentRequestType::MetadataCorrection->value,
            'catalog_title_id' => $title->id,
            'field' => ContentCorrectionField::Tag->value,
            'target' => $tag->id,
        ])->test(ContentRequestFormPage::class)
            ->assertSet('correctionField', ContentCorrectionField::Tag->value)
            ->assertSet('correctionTargetKey', 'tag:'.$tag->id)
            ->assertSet('currentValue', 'Гномы')
            ->assertSet('proposedValue', 'Удалить тег «Гномы»')
            ->assertSet('correctionReason', '')
            ->assertSeeText('Не относится к сериалу')
            ->set('proposedValue', 'Удалить неверный тег')
            ->call('submit')
            ->assertHasErrors(['correctionReason'])
            ->assertSeeText('Выберите причину исправления тега.');
    }

    public function test_forged_application_action_cannot_create_a_correction_for_a_regular_user(): void
    {
        $requester = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();

        $this->expectException(AuthorizationException::class);

        app(CreateContentRequest::class)->handle(
            $requester,
            $this->correctionInput($title, $tag),
        );
    }

    public function test_administrator_creates_a_private_correction_without_public_engagement_artifacts(): void
    {
        $administrator = $this->administrator();
        [$title, $tag] = $this->catalogFixture();

        $request = app(CreateContentRequest::class)->handle(
            $administrator,
            $this->correctionInput($title, $tag),
        );

        self::assertFalse($request->is_public);
        self::assertSame(0, $request->votes()->count());
        self::assertSame(0, $request->followers()->count());
        self::assertTrue(Gate::forUser($administrator)->allows('view', $request));

        $this->get(route('requests.show', $request))->assertNotFound();
        $this->actingAs($administrator)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSeeText('Удалить тег');
        $this->get(route('admin.requests'))
            ->assertOk()
            ->assertSeeText($request->title);
    }

    public function test_historical_public_flag_cannot_expose_a_correction_to_its_requester_or_public_surfaces(): void
    {
        $administrator = $this->administrator();
        $requester = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();
        $request = $this->createTagCorrection($administrator, $title, $tag);
        $request->forceFill([
            'requester_id' => $requester->id,
            'is_public' => true,
        ])->save();

        self::assertFalse(ContentRequest::query()->publiclyVisible()->whereKey($request)->exists());
        self::assertFalse(Gate::forUser($requester)->allows('view', $request));
        self::assertFalse(Gate::forUser($requester)->allows('update', $request));
        self::assertFalse(Gate::forUser($requester)->allows('withdraw', $request));
        self::assertFalse(Gate::forUser($requester)->allows('vote', $request));
        self::assertFalse(Gate::forUser($requester)->allows('follow', $request));
        self::assertFalse(Gate::forUser($requester)->allows('clarify', $request));

        $this->actingAs($requester)
            ->get(route('requests.show', $request))
            ->assertNotFound();
        $this->get(route('requests.index'))
            ->assertOk()
            ->assertDontSeeText($request->title);
        $this->get(route('requests.mine'))
            ->assertOk()
            ->assertDontSeeText($request->title);
        $this->get(route('sitemap.requests', ['page' => 1]))
            ->assertOk()
            ->assertDontSee($request->public_id);

        $this->actingAs($administrator)
            ->get(route('requests.show', $request))
            ->assertOk();
    }

    public function test_regular_user_cannot_vote_for_a_historical_public_flag_correction(): void
    {
        $administrator = $this->administrator();
        [$title, $tag] = $this->catalogFixture();
        $request = $this->createTagCorrection($administrator, $title, $tag);
        $request->forceFill(['is_public' => true])->save();

        $this->expectException(AuthorizationException::class);

        app(SetContentRequestEngagement::class)->vote(
            User::factory()->create(),
            $request->id,
            true,
        );
    }

    public function test_historical_correction_notifications_do_not_link_or_deliver_to_regular_users(): void
    {
        $administrator = $this->administrator();
        $regularUser = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();
        $request = $this->createTagCorrection($administrator, $title, $tag);
        $request->forceFill([
            'requester_id' => $regularUser->id,
            'is_public' => true,
        ])->save();

        app(ContentRequestNotificationService::class)->statusChanged($request, $administrator);
        $this->assertSame(0, $regularUser->notifications()->count());

        $regularUser->notify(new ContentRequestActivityNotification(
            ContentRequestNotificationType::StatusChanged,
            $request->public_id,
            $request->status->value,
        ));
        $notification = app(ContentRequestNotificationQuery::class)
            ->forUser($regularUser)
            ->items()[0];

        self::assertNull($notification->url);
    }

    public function test_help_escalation_fails_closed_for_a_stored_administrative_request_type(): void
    {
        $article = HelpArticle::query()
            ->where('code', 'release-calendar-and-recommendations')
            ->firstOrFail();
        $article->forceFill([
            'secondary_escalation' => HelpEscalationType::ContentRequest,
            'escalation_request_type' => ContentRequestType::MetadataCorrection->value,
        ])->save();

        $escalations = app(HelpEscalationService::class)->for($article, null);

        self::assertNotContains(
            HelpEscalationType::ContentRequest,
            array_map(static fn ($item): HelpEscalationType => $item->type, $escalations),
        );
    }

    public function test_administrator_input_is_sanitized_and_visible_only_inside_the_admin_boundary(): void
    {
        $administrator = $this->administrator();
        [$title, $tag] = $this->catalogFixture();
        $input = $this->correctionInput(
            $title,
            $tag,
            '<script>alert(1)</script><b>Удалить неверный тег</b>',
        );

        $request = app(CreateContentRequest::class)->handle($administrator, $input);

        self::assertSame('Удалить неверный тег', $request->proposed_value);
        $this->get(route('requests.show', $request))->assertNotFound();
        $this->actingAs($administrator)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSeeText('Удалить неверный тег')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Удалить неверный тег</b>', false);
    }

    public function test_administrator_can_moderate_corrections_while_regular_user_cannot(): void
    {
        $administrator = $this->administrator();
        $regularUser = User::factory()->create();
        [$title, $firstTag] = $this->catalogFixture();
        $secondTag = Tag::query()->create([
            'name' => 'Фантомы',
            'slug' => 'fantomy-field-correction',
        ]);
        $title->tags()->attach($secondTag);
        $approved = $this->createTagCorrection($administrator, $title, $firstTag);
        $rejected = $this->createTagCorrection(
            $administrator,
            $title,
            $secondTag,
            ContentCorrectionReason::TooBroad,
            'Вторая цель относится к другому тегу карточки.',
        );
        $statuses = app(ChangeContentRequestStatus::class);

        $this->assertThrows(
            fn () => $statuses->handle(
                $regularUser,
                $approved->id,
                ContentRequestStatus::Approved,
                $approved->version,
            ),
            AuthorizationException::class,
        );

        $statuses->handle(
            $administrator,
            $approved->id,
            ContentRequestStatus::Approved,
            $approved->version,
            'Тег проверен модератором.',
        );
        $statuses->handle(
            $administrator,
            $rejected->id,
            ContentRequestStatus::Rejected,
            $rejected->version,
            'Тег соответствует карточке.',
            rejectionReason: ContentRequestRejectionReason::UnverifiableContent,
        );

        self::assertSame(ContentRequestStatus::Approved, $approved->refresh()->status);
        self::assertSame(ContentRequestStatus::Rejected, $rejected->refresh()->status);
    }

    public function test_distinct_administrative_tag_targets_keep_distinct_identity_and_reason(): void
    {
        $administrator = $this->administrator();
        [$title, $firstTag] = $this->catalogFixture();
        $secondTag = Tag::query()->create([
            'name' => 'Друиды',
            'slug' => 'druidy-field-correction',
        ]);
        $title->tags()->attach($secondTag);

        $first = $this->createTagCorrection($administrator, $title, $firstTag);
        $second = $this->createTagCorrection(
            $administrator,
            $title,
            $secondTag,
            ContentCorrectionReason::ImportError,
            'Это отдельная цель исправления с другим target key.',
        );

        self::assertNotSame($first->exact_identity_hash, $second->exact_identity_hash);
        self::assertSame('tag:'.$firstTag->id, $first->correction_target_key);
        self::assertSame(ContentCorrectionReason::NotRelated, $first->correction_reason);
        self::assertSame('tag:'.$secondTag->id, $second->correction_target_key);
        self::assertSame(ContentCorrectionReason::ImportError, $second->correction_reason);
    }

    public function test_action_revalidates_locked_target_data_and_tag_reason_for_administrator(): void
    {
        $administrator = $this->administrator();
        [$title, $tag] = $this->catalogFixture();
        $factory = app(ContentRequestInputFactory::class);
        $base = [
            'type' => ContentRequestType::MetadataCorrection->value,
            'title' => $title->display_title,
            'catalog_title_id' => $title->id,
            'correction_field' => ContentCorrectionField::Tag->value,
            'correction_target_key' => 'tag:'.$tag->id,
            'current_value' => $tag->name,
            'proposed_value' => 'Удалить неверный тег',
            'submission_token' => (string) Str::uuid(),
        ];

        $this->assertThrows(
            fn () => app(CreateContentRequest::class)->handle($administrator, $factory->from($base)),
            ContentRequestActionException::class,
        );
        $this->assertThrows(
            fn () => app(CreateContentRequest::class)->handle($administrator, $factory->from([
                ...$base,
                'correction_reason' => ContentCorrectionReason::NotRelated->value,
                'current_value' => 'Подменённое значение',
                'submission_token' => (string) Str::uuid(),
            ])),
            ContentRequestActionException::class,
        );

        $this->assertDatabaseCount('content_requests', 0);
    }

    public function test_resolver_rejects_a_relation_that_does_not_belong_to_the_title(): void
    {
        [$title] = $this->catalogFixture();
        $foreignTag = Tag::query()->create([
            'name' => 'Чужой тег',
            'slug' => 'foreign-correction-tag',
        ]);

        $this->expectException(ContentRequestActionException::class);

        app(CatalogCorrectionTargetResolver::class)->resolve(
            $title,
            ContentCorrectionField::Tag,
            $foreignTag->id,
            null,
        );
    }

    public function test_poster_context_does_not_disclose_the_stored_url_to_the_admin_form(): void
    {
        [$title] = $this->catalogFixture();

        $target = app(CatalogCorrectionTargetResolver::class)->resolve(
            $title,
            ContentCorrectionField::Poster,
            null,
            null,
        );

        self::assertSame('Постер установлен', $target->currentValue);
        self::assertStringNotContainsString((string) $title->poster_url, $target->currentValue);
    }

    private function administrator(): User
    {
        config(['seasonvar.admin_emails' => ['catalog-admin@example.com']]);

        return User::factory()->create(['email' => 'catalog-admin@example.com']);
    }

    private function correctionInput(
        CatalogTitle $title,
        Tag $tag,
        string $proposedValue = 'Удалить тег «Гномы»',
        ContentCorrectionReason $reason = ContentCorrectionReason::NotRelated,
    ): ContentRequestInput {
        return app(ContentRequestInputFactory::class)->from([
            'type' => ContentRequestType::MetadataCorrection->value,
            'title' => $title->display_title,
            'release_year' => $title->year,
            'catalog_title_id' => $title->id,
            'correction_field' => ContentCorrectionField::Tag->value,
            'correction_target_key' => 'tag:'.$tag->id,
            'correction_reason' => $reason->value,
            'current_value' => $tag->name,
            'proposed_value' => $proposedValue,
            'submission_token' => (string) Str::uuid(),
        ]);
    }

    private function createTagCorrection(
        User $administrator,
        CatalogTitle $title,
        Tag $tag,
        ContentCorrectionReason $reason = ContentCorrectionReason::NotRelated,
        ?string $differentExplanation = null,
    ): ContentRequest {
        $input = app(ContentRequestInputFactory::class)->from([
            'type' => ContentRequestType::MetadataCorrection->value,
            'title' => $title->display_title,
            'release_year' => $title->year,
            'catalog_title_id' => $title->id,
            'correction_field' => ContentCorrectionField::Tag->value,
            'correction_target_key' => 'tag:'.$tag->id,
            'correction_reason' => $reason->value,
            'current_value' => $tag->name,
            'proposed_value' => 'Удалить тег «'.$tag->name.'»',
            'different_explanation' => $differentExplanation,
            'submission_token' => (string) Str::uuid(),
        ]);

        return app(CreateContentRequest::class)->handle($administrator, $input);
    }

    /** @return array{CatalogTitle, Tag, Episode} */
    private function catalogFixture(): array
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'year' => 2020,
            'description' => 'Криминальная корейская дорама.',
            'poster_url' => 'https://seasonvar.ru/posters/cvetok-zla.jpg',
        ]);
        $genre = Genre::query()->create(['name' => 'Криминал', 'slug' => 'crime-field-correction']);
        $country = Country::query()->create(['name' => 'Южная Корея', 'slug' => 'south-korea-field-correction']);
        $actor = Actor::query()->create(['name' => 'Ли Джун-ги', 'slug' => 'lee-joon-gi-field-correction']);
        $translation = Translation::query()->create(['name' => 'Русская озвучка', 'slug' => 'russian-field-correction']);
        $tag = Tag::query()->create(['name' => 'Гномы', 'slug' => 'gnomy-field-correction']);
        $title->genres()->attach($genre);
        $title->countries()->attach($country);
        $title->actors()->attach($actor);
        $title->translations()->attach($translation);
        $title->tags()->attach($tag);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 7, 'title' => 'Седьмая серия']);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'has_subtitles' => true,
            'published_at' => now(),
        ]);

        return [$title, $tag, $episode];
    }
}
