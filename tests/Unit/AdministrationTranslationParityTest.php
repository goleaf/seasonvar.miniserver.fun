<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdministrationTranslationParityTest extends TestCase
{
    #[Test]
    public function administration_locales_have_identical_keys_and_placeholders(): void
    {
        $russian = Arr::dot(require lang_path('ru/administration.php'));
        $english = Arr::dot(require lang_path('en/administration.php'));

        self::assertSame(array_keys($russian), array_keys($english));

        foreach ($russian as $key => $value) {
            self::assertIsString($value, $key);
            self::assertIsString($english[$key], $key);
            self::assertSame($this->placeholders($value), $this->placeholders($english[$key]), $key);
        }
    }

    #[Test]
    public function every_stable_role_permission_and_audit_action_has_both_locale_labels(): void
    {
        foreach (AdminRoleCode::cases() as $role) {
            $this->assertTranslated('administration.roles.'.$role->value);
        }

        foreach (AdminPermission::cases() as $permission) {
            $this->assertTranslated('administration.permissions.'.str_replace('.', '_', $permission->value));
        }

        foreach (AdminAuditAction::cases() as $action) {
            $this->assertTranslated('administration.audit.actions.'.str_replace('.', '_', $action->value));
        }
    }

    /** @return list<string> */
    private function placeholders(string $value): array
    {
        preg_match_all('/(?<![A-Za-z0-9:]):[A-Za-z_][A-Za-z0-9_]*/', $value, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }

    private function assertTranslated(string $key): void
    {
        foreach (['ru', 'en'] as $locale) {
            $translated = trans($key, locale: $locale);
            self::assertNotSame($key, $translated, $locale.':'.$key);
        }
    }
}
