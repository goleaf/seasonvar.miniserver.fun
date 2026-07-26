<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ContentRequests\ChangeContentRequestStatus;
use App\Actions\ContentRequests\CreateContentRequest;
use App\Actions\ContentRequests\SetContentRequestEngagement;
use App\Enums\ContentCorrectionField;
use App\Enums\ContentCorrectionReason;
use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestStatus;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Livewire\ContentRequests\ContentRequestFormPage;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\User;
use App\Services\ContentRequests\CatalogCorrectionTargetResolver;
use App\Services\ContentRequests\ContentRequestInputFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogFieldCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_supports_a_specific_correction_target_and_reason(): void
    {
        self::assertTrue(Schema::hasColumns('content_requests', [
            'correction_target_key',
            'correction_reason',
        ]));
    }

    public function test_title_page_exposes_contextual_correction_actions_for_every_supported_field(): void
    {
        $user = User::factory()->create();
        [$title, $tag, $episode] = $this->catalogFixture();

        $response = $this->actingAs($user)->get(route('titles.show', [
            'catalogTitle' => $title,
            'season' => $episode->season_id,
            'episode' => $episode->id,
        ]));

        $response->assertOk();

        foreach (ContentCorrectionField::cases() as $field) {
            $response->assertSee('data-correction-field="'.$field->value.'"', false);
        }

        $response
            ->assertSee('field=tag', false)
            ->assertSee('target='.$tag->id, false)
            ->assertSee('field=episode', false)
            ->assertSee('target='.$episode->id, false)
            ->assertSee('field=subtitles', false);
    }

    public function test_tag_shortcut_is_prefilled_from_the_server_and_requires_a_reason(): void
    {
        $user = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();
        $this->actingAs($user);

        Livewire::withQueryParams([
            'type' => 'metadata_correction',
            'catalog_title_id' => $title->id,
            'field' => 'tag',
            'target' => $tag->id,
        ])->test(ContentRequestFormPage::class)
            ->assertSet('correctionField', 'tag')
            ->assertSet('correctionTargetKey', 'tag:'.$tag->id)
            ->assertSet('currentValue', 'Гномы')
            ->assertSet('proposedValue', 'Удалить тег «Гномы»')
            ->assertSet('correctionReason', '')
            ->assertSeeText('Не относится к сериалу')
            ->assertSeeText('Дубликат')
            ->assertSeeText('Ошибка перевода')
            ->assertSeeText('Слишком общий')
            ->assertSeeText('Ошибка импорта')
            ->set('proposedValue', 'Удалить неверный тег')
            ->call('submit')
            ->assertHasErrors(['correctionReason'])
            ->assertSeeText('Выберите причину исправления тега.')
            ->call('clearCatalogTitle')
            ->assertSet('catalogTitleId', '')
            ->assertSet('correctionTargetKey', '')
            ->assertSet('correctionReason', '')
            ->assertSet('correctionContextLocked', false)
            ->assertSet('currentValue', '')
            ->assertSet('proposedValue', '');
    }

    public function test_correction_form_requires_authentication(): void
    {
        [$title, $tag] = $this->catalogFixture();

        $this->get(route('requests.create', [
            'type' => 'metadata_correction',
            'catalog_title_id' => $title->id,
            'field' => 'tag',
            'target' => $tag->id,
        ]))->assertRedirect(route('login'));
    }

    public function test_unverified_user_cannot_open_a_correction_form(): void
    {
        $user = User::factory()->unverified()->create();
        [$title, $tag] = $this->catalogFixture();

        $this->actingAs($user)->get(route('requests.create', [
            'type' => 'metadata_correction',
            'catalog_title_id' => $title->id,
            'field' => 'tag',
            'target' => $tag->id,
        ]))->assertForbidden();
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

    public function test_poster_correction_context_does_not_disclose_the_stored_url(): void
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

    public function test_distinct_tag_targets_have_distinct_identity_and_persist_the_selected_reason(): void
    {
        $requester = User::factory()->create();
        [$title, $firstTag] = $this->catalogFixture();
        $secondTag = Tag::query()->create([
            'name' => 'Друиды',
            'slug' => 'druidy-field-correction',
        ]);
        $title->tags()->attach($secondTag);

        $first = $this->createTagCorrection($requester, $title, $firstTag);
        $second = $this->createTagCorrection(
            $requester,
            $title,
            $secondTag,
            ContentCorrectionReason::ImportError,
            'Это отдельный неверный тег, поэтому предложение не является повтором.',
        );

        self::assertNotSame($first->exact_identity_hash, $second->exact_identity_hash);
        self::assertSame('tag:'.$firstTag->id, $first->correction_target_key);
        self::assertSame(ContentCorrectionReason::NotRelated, $first->correction_reason);
        self::assertSame('tag:'.$secondTag->id, $second->correction_target_key);
        self::assertSame(ContentCorrectionReason::ImportError, $second->correction_reason);
    }

    public function test_action_revalidates_locked_target_data_and_tag_reason(): void
    {
        $requester = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();
        $factory = app(ContentRequestInputFactory::class);
        $base = [
            'type' => 'metadata_correction',
            'title' => $title->display_title,
            'catalog_title_id' => $title->id,
            'correction_field' => 'tag',
            'correction_target_key' => 'tag:'.$tag->id,
            'current_value' => $tag->name,
            'proposed_value' => 'Удалить неверный тег',
            'submission_token' => (string) Str::uuid(),
        ];

        $this->assertThrows(
            fn () => app(CreateContentRequest::class)->handle($requester, $factory->from($base)),
            ContentRequestActionException::class,
        );

        $this->assertThrows(
            fn () => app(CreateContentRequest::class)->handle($requester, $factory->from([
                ...$base,
                'correction_reason' => ContentCorrectionReason::NotRelated->value,
                'current_value' => 'Подменённое значение',
                'submission_token' => (string) Str::uuid(),
            ])),
            ContentRequestActionException::class,
        );

        $this->assertDatabaseCount('content_requests', 0);
    }

    public function test_other_verified_users_can_support_once_and_unverified_users_cannot_vote(): void
    {
        $requester = User::factory()->create();
        $supporter = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        [$title, $tag] = $this->catalogFixture();
        $request = $this->createTagCorrection($requester, $title, $tag);
        $engagement = app(SetContentRequestEngagement::class);

        $engagement->vote($supporter, $request->id, true);
        $engagement->vote($supporter, $request->id, true);

        self::assertSame(2, $request->votes()->count());

        $this->expectException(AuthorizationException::class);
        $engagement->vote($unverified, $request->id, true);
    }

    public function test_user_proposal_is_sanitized_before_it_is_rendered_publicly(): void
    {
        $requester = User::factory()->create();
        [$title, $tag] = $this->catalogFixture();
        $input = app(ContentRequestInputFactory::class)->from([
            'type' => 'metadata_correction',
            'title' => $title->display_title,
            'catalog_title_id' => $title->id,
            'correction_field' => 'tag',
            'correction_target_key' => 'tag:'.$tag->id,
            'correction_reason' => ContentCorrectionReason::ImportError->value,
            'current_value' => $tag->name,
            'proposed_value' => '<script>alert(1)</script><b>Удалить неверный тег</b>',
            'submission_token' => (string) Str::uuid(),
        ]);

        $request = app(CreateContentRequest::class)->handle($requester, $input);

        self::assertSame('Удалить неверный тег', $request->proposed_value);
        $this->get(route('requests.show', $request))
            ->assertOk()
            ->assertSeeText('Удалить неверный тег')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Удалить неверный тег</b>', false);
    }

    public function test_moderator_can_accept_or_reject_field_corrections_and_reason_is_publicly_explained(): void
    {
        config(['seasonvar.admin_emails' => ['moderator@example.com']]);
        $requester = User::factory()->create();
        $moderator = User::factory()->create(['email' => 'moderator@example.com']);
        [$title, $firstTag] = $this->catalogFixture();
        $secondTag = Tag::query()->create([
            'name' => 'Фантомы',
            'slug' => 'fantomy-field-correction',
        ]);
        $title->tags()->attach($secondTag);
        $approved = $this->createTagCorrection($requester, $title, $firstTag);
        $rejected = $this->createTagCorrection(
            $requester,
            $title,
            $secondTag,
            ContentCorrectionReason::TooBroad,
            'Вторая цель проверяется отдельно от первого предложения.',
        );
        $statuses = app(ChangeContentRequestStatus::class);

        $this->assertThrows(
            fn () => $statuses->handle(
                User::factory()->create(),
                $approved->id,
                ContentRequestStatus::Approved,
                $approved->version,
            ),
            AuthorizationException::class,
        );

        $statuses->handle(
            $moderator,
            $approved->id,
            ContentRequestStatus::Approved,
            $approved->version,
            'Тег проверен модератором.',
        );
        $statuses->handle(
            $moderator,
            $rejected->id,
            ContentRequestStatus::Rejected,
            $rejected->version,
            'Тег соответствует карточке.',
            rejectionReason: ContentRequestRejectionReason::UnverifiableContent,
        );

        self::assertSame(ContentRequestStatus::Approved, $approved->refresh()->status);
        self::assertSame(ContentRequestStatus::Rejected, $rejected->refresh()->status);

        $this->get(route('requests.show', $approved))
            ->assertOk()
            ->assertSeeText('Не относится к сериалу')
            ->assertSeeText('Гномы')
            ->assertSeeText('Удалить тег');
    }

    private function createTagCorrection(
        User $requester,
        CatalogTitle $title,
        Tag $tag,
        ContentCorrectionReason $reason = ContentCorrectionReason::NotRelated,
        ?string $differentExplanation = null,
    ): ContentRequest {
        $input = app(ContentRequestInputFactory::class)->from([
            'type' => 'metadata_correction',
            'title' => $title->display_title,
            'release_year' => $title->year,
            'catalog_title_id' => $title->id,
            'correction_field' => 'tag',
            'correction_target_key' => 'tag:'.$tag->id,
            'correction_reason' => $reason->value,
            'current_value' => $tag->name,
            'proposed_value' => 'Удалить тег «'.$tag->name.'»',
            'different_explanation' => $differentExplanation,
            'submission_token' => (string) Str::uuid(),
        ]);

        return app(CreateContentRequest::class)->handle($requester, $input);
    }

    /**
     * @return array{CatalogTitle, Tag, Episode}
     */
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
