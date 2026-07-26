<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\PublicationStatus;
use App\Livewire\CatalogSeries;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogTitleCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_card_actions_redirect_to_login_without_writing_state(): void
    {
        $title = CatalogTitle::factory()->create();

        Livewire::test(CatalogSeries::class)
            ->call('setCardWatchlist', $title->id, true)
            ->assertRedirect(route('login'));

        Livewire::test(CatalogSeries::class)
            ->call('setCardFeedbackReason', $title->id, 'too_old')
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'catalog_title_id' => $title->id,
        ]);
        $this->assertSame(0, CatalogRecommendationFeedbackDetail::query()->count());
    }

    public function test_verified_user_can_add_and_remove_the_visible_title_from_the_library(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'Карточка с библиотекой',
        ]);

        $component = Livewire::actingAs($user)
            ->test(CatalogSeries::class)
            ->call('setCardWatchlist', $title->id, true)
            ->assertHasNoErrors()
            ->assertSet('cardActionNotice', 'Сериал добавлен в библиотеку.');

        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
        ]);

        $component
            ->call('setCardWatchlist', $title->id, false)
            ->assertHasNoErrors()
            ->assertSet('cardActionNotice', 'Сериал удалён из библиотеки.');

        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => false,
        ]);
    }

    public function test_unverified_user_cannot_mutate_card_state(): void
    {
        $user = User::factory()->unverified()->create();
        $title = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogSeries::class)
            ->call('setCardWatchlist', $title->id, true)
            ->assertForbidden();

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
    }

    public function test_card_action_rejects_invalid_scalar_and_boolean_input_without_a_write(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogSeries::class)
            ->call('setCardWatchlist', ['unexpected'], 'definitely')
            ->assertHasErrors(['cardAction']);

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
    }

    public function test_hidden_title_id_cannot_be_mutated_through_the_catalog_card(): void
    {
        $user = User::factory()->create();
        $hidden = CatalogTitle::factory()->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);

        $exception = null;

        try {
            Livewire::actingAs($user)
                ->test(CatalogSeries::class)
                ->call('setCardWatchlist', $hidden->id, true);
        } catch (ModelNotFoundException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $exception);
        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $hidden->id,
        ]);
    }

    public function test_verified_user_can_save_a_non_subject_feedback_reason_from_the_card(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogSeries::class)
            ->call('setCardFeedbackReason', $title->id, CatalogRecommendationFeedbackReason::TooOld->value)
            ->assertHasNoErrors()
            ->assertSet('cardActionNotice', 'Причина учтена. Этот сериал скрыт, а следующие рекомендации будут точнее.');

        $detail = CatalogRecommendationFeedbackDetail::query()->sole();
        $this->assertSame($user->id, $detail->user_id);
        $this->assertSame($title->id, $detail->catalog_title_id);
        $this->assertSame(CatalogRecommendationFeedbackReason::TooOld, $detail->reason);
        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => 'not_interested',
        ]);
    }

    public function test_card_feedback_rejects_unknown_and_subject_reasons_without_writing(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $component = Livewire::actingAs($user)->test(CatalogSeries::class);

        $component
            ->call('setCardFeedbackReason', $title->id, 'unknown')
            ->assertHasErrors(['cardAction']);
        $component
            ->call('setCardFeedbackReason', $title->id, CatalogRecommendationFeedbackReason::DislikeGenre->value)
            ->assertHasErrors(['cardAction']);

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
        $this->assertSame(0, CatalogRecommendationFeedbackDetail::query()->count());
    }
}
