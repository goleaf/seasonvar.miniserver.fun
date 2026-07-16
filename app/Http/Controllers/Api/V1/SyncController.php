<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ApiSyncPullResult;
use App\Exceptions\ApiSyncCursorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncPullRequest;
use App\Http\Requests\Api\V1\SyncPushRequest;
use App\Http\Resources\Api\V1\SyncChangeResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiSyncChange;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncCursorCodec;
use App\Services\Api\V1\Sync\ApiSyncMutationService;
use App\Services\Api\V1\Sync\ApiSyncPullQuery;
use App\Services\Api\V1\Sync\ApiSyncPullService;
use App\Services\Api\V1\Sync\ApiSyncReadiness;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncController extends Controller
{
    public function manifest(
        Request $request,
        ApiSyncReadiness $readiness,
        ApiSyncPullQuery $pull,
        ApiSyncCursorCodec $cursors,
        ApiErrorResponse $errors,
    ): JsonResponse {
        if (! $readiness->available()) {
            return $this->unavailable($request, $errors);
        }

        return response()->json(['data' => [
            'sync_version' => 1,
            'cursor' => $cursors->encode($pull->checkpoint(ApiSyncChange::SCOPE_CATALOG, null)),
            'retention_days' => (int) config('mobile-api.sync.change_retention_days', 30),
            'max_pull_items' => (int) config('mobile-api.sync.max_pull_items', 200),
            'max_push_items' => (int) config('mobile-api.sync.max_push_items', 50),
            'links' => [
                'filters' => route('api.v1.catalog.filters'),
                'directories' => route('api.v1.catalog.directories.index'),
                'titles' => route('api.v1.titles.index'),
                'changes' => route('api.v1.sync.changes'),
                'openapi' => route('api.openapi'),
            ],
            'bootstrap' => 'Сохраните курсор, загрузите текущий каталог постранично, затем запросите изменения от сохранённого курсора.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function catalog(
        SyncPullRequest $request,
        ApiSyncReadiness $readiness,
        ApiSyncPullService $sync,
        ApiErrorResponse $errors,
    ): JsonResponse {
        if (! $readiness->available()) {
            return $this->unavailable($request, $errors);
        }

        try {
            $result = $sync->pull(
                ApiSyncChange::SCOPE_CATALOG,
                null,
                $request->cursor(),
                $request->limit(),
            );
        } catch (ApiSyncCursorException $exception) {
            return $this->cursorError($request, $errors, $exception);
        }

        return $this->pullResponse($request, $result);
    }

    public function user(
        SyncPullRequest $request,
        ApiSyncReadiness $readiness,
        ApiSyncPullService $sync,
        ApiErrorResponse $errors,
    ): JsonResponse {
        if (! $readiness->available()) {
            return $this->unavailable($request, $errors);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        try {
            $result = $sync->pull(
                ApiSyncChange::SCOPE_USER,
                $user,
                $request->cursor(),
                $request->limit(),
            );
        } catch (ApiSyncCursorException $exception) {
            return $this->cursorError($request, $errors, $exception);
        }

        return $this->pullResponse($request, $result);
    }

    private function pullResponse(SyncPullRequest $request, ApiSyncPullResult $result): JsonResponse
    {
        return SyncChangeResource::collection($result->changes)
            ->additional(['meta' => [
                'cursor' => $result->cursor,
                'has_more' => $result->hasMore,
                'limit' => $request->limit(),
            ]])
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function push(
        SyncPushRequest $request,
        ApiSyncReadiness $readiness,
        ApiSyncMutationService $mutations,
        ApiErrorResponse $errors,
    ): JsonResponse {
        if (! $readiness->available()) {
            return $this->unavailable($request, $errors);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $results = collect($request->operations())
            ->map(fn (array $operation): array => $mutations->apply($user, $operation)->toArray())
            ->values()
            ->all();

        return response()->json([
            'data' => ['results' => $results],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function cursorError(
        Request $request,
        ApiErrorResponse $errors,
        ApiSyncCursorException $exception,
    ): JsonResponse {
        if ($exception->reason === ApiSyncCursorException::EXPIRED) {
            return $errors->make(
                $request,
                'sync_cursor_expired',
                'Курсор синхронизации устарел. Выполните полную загрузку заново.',
                410,
            );
        }

        return $errors->make(
            $request,
            'validation_failed',
            'Переданные данные некорректны.',
            422,
            ['cursor' => ['Некорректный курсор синхронизации.']],
        );
    }

    private function unavailable(Request $request, ApiErrorResponse $errors): JsonResponse
    {
        return $errors->make(
            $request,
            'sync_unavailable',
            'Синхронизация временно недоступна.',
            503,
        );
    }
}
