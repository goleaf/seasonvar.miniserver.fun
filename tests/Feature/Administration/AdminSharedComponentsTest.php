<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\DTOs\Administration\AdminBulkActionData;
use App\Enums\AdminPermission;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminSharedComponentsTest extends TestCase
{
    #[Test]
    public function bulk_action_definitions_are_bounded_and_always_require_preview(): void
    {
        $action = new AdminBulkActionData(
            code: 'users.restrict',
            label: 'Ограничить',
            permission: AdminPermission::UsersRestrict,
            maximumItems: 50,
            destructive: true,
        );

        self::assertTrue($action->previewRequired);
        self::assertSame(50, $action->maximumItems);
    }

    #[Test]
    public function shared_states_and_confirmation_have_accessible_semantics(): void
    {
        $empty = Blade::render('<x-administration.state type="empty" title="Нет данных" description="Измените фильтры." />');
        $error = Blade::render('<x-administration.state type="error" title="Ошибка" description="Повторите позже." />');
        $confirmation = Blade::render('<x-administration.action-confirmation action="users.restrict" title="Подтверждение" impact="Будет ограничена одна запись." />');

        self::assertStringContainsString('role="status"', $empty);
        self::assertStringContainsString('aria-live="polite"', $error);
        self::assertStringContainsString('data-impact-preview', $confirmation);
        self::assertStringContainsString('users.restrict', $confirmation);
        self::assertStringContainsString('type="button"', $confirmation);
    }

    #[Test]
    public function shared_filters_allow_long_translated_controls_to_shrink_on_mobile(): void
    {
        $filters = Blade::render(<<<'BLADE'
            <x-administration.filters label="Фильтры">
                <label><select><option>Очень длинная переведённая административная операция</option></select></label>
            </x-administration.filters>
        BLADE);

        self::assertStringContainsString('min-w-0', $filters);
        self::assertStringContainsString('[&>*]:min-w-0', $filters);
    }
}
