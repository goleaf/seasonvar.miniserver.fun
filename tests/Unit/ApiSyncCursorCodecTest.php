<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\ApiSyncCursor;
use App\Exceptions\ApiSyncCursorException;
use App\Models\ApiSyncChange;
use App\Services\Api\V1\Sync\ApiSyncCursorCodec;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ApiSyncCursorCodecTest extends TestCase
{
    public function test_catalog_cursor_round_trips_without_exposing_its_payload(): void
    {
        $codec = app(ApiSyncCursorCodec::class);
        $cursor = new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 42);

        $encoded = $codec->encode($cursor);
        $decoded = $codec->decode($encoded, ApiSyncChange::SCOPE_CATALOG, null);

        $this->assertSame(ApiSyncChange::SCOPE_CATALOG, $decoded->scope);
        $this->assertNull($decoded->ownerId);
        $this->assertSame(42, $decoded->changeId);
        $this->assertGreaterThan(0, $decoded->issuedAt);

        $envelope = base64_decode($encoded, true);
        $this->assertNotFalse($envelope);
        $this->assertJson($envelope);
        $encryptedPayload = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($encryptedPayload);
        $this->assertArrayHasKey('value', $encryptedPayload);
        $this->assertIsString($encryptedPayload['value']);
        $ciphertext = base64_decode($encryptedPayload['value'], true);
        $this->assertNotFalse($ciphertext);
        $this->assertNotSame(json_encode([
            'v' => 1,
            's' => ApiSyncChange::SCOPE_CATALOG,
            'o' => null,
            'i' => 42,
            't' => $decoded->issuedAt,
        ], JSON_THROW_ON_ERROR), $ciphertext);
    }

    public function test_user_cursor_is_bound_to_its_owner(): void
    {
        $codec = app(ApiSyncCursorCodec::class);
        $encoded = $codec->encode(new ApiSyncCursor(ApiSyncChange::SCOPE_USER, 17, 125));

        $decoded = $codec->decode($encoded, ApiSyncChange::SCOPE_USER, 17);

        $this->assertSame(17, $decoded->ownerId);
        $this->assertSame(125, $decoded->changeId);
    }

    public function test_tampered_cursor_is_reported_as_invalid_without_sensitive_context(): void
    {
        $codec = app(ApiSyncCursorCodec::class);
        $encoded = $codec->encode(new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 1));

        try {
            $codec->decode(substr($encoded, 0, -1).'x', ApiSyncChange::SCOPE_CATALOG, null);
            $this->fail('Tampered cursor was accepted.');
        } catch (ApiSyncCursorException $exception) {
            $this->assertSame(ApiSyncCursorException::INVALID, $exception->reason);
            $this->assertSame('Некорректный курсор синхронизации.', $exception->getMessage());
            $this->assertSame([], $exception->context());
        }
    }

    public function test_cursor_rejects_scope_and_owner_mismatches(): void
    {
        $codec = app(ApiSyncCursorCodec::class);
        $catalog = $codec->encode(new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 9));
        $user = $codec->encode(new ApiSyncCursor(ApiSyncChange::SCOPE_USER, 8, 9));

        $this->assertDecodeReason(
            $codec,
            $catalog,
            ApiSyncChange::SCOPE_USER,
            8,
            ApiSyncCursorException::SCOPE_MISMATCH,
        );
        $this->assertDecodeReason(
            $codec,
            $user,
            ApiSyncChange::SCOPE_USER,
            9,
            ApiSyncCursorException::OWNER_MISMATCH,
        );
    }

    /** @param array<string, mixed>|list<mixed>|string $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function test_cursor_rejects_malformed_decrypted_payloads(array|string $payload): void
    {
        $encoded = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertDecodeReason(
            app(ApiSyncCursorCodec::class),
            $encoded,
            ApiSyncChange::SCOPE_CATALOG,
            null,
            ApiSyncCursorException::INVALID,
        );
    }

    /** @return iterable<string, array{array<string, mixed>|list<mixed>|string}> */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'not an object' => ['cursor'];
        yield 'unknown version' => [['v' => 2, 's' => 'catalog', 'o' => null, 'i' => 1]];
        yield 'missing owner' => [['v' => 1, 's' => 'catalog', 'i' => 1]];
        yield 'negative id' => [['v' => 1, 's' => 'catalog', 'o' => null, 'i' => -1]];
        yield 'string id' => [['v' => 1, 's' => 'catalog', 'o' => null, 'i' => '1']];
        yield 'unexpected key' => [['v' => 1, 's' => 'catalog', 'o' => null, 'i' => 1, 'x' => true]];
    }

    private function assertDecodeReason(
        ApiSyncCursorCodec $codec,
        string $encoded,
        string $scope,
        ?int $ownerId,
        string $reason,
    ): void {
        try {
            $codec->decode($encoded, $scope, $ownerId);
            $this->fail('Invalid cursor was accepted.');
        } catch (ApiSyncCursorException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
