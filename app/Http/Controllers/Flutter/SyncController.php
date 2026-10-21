<?php

namespace App\Http\Controllers\Flutter;

use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\FlutterSyncConflict;
use App\Models\FlutterSyncRevision;
use App\Models\FlutterSyncSnapshot;
use App\Http\Controllers\Controller;
use App\Models\FlutterSyncOperation;
use App\Services\FlutterSyncService;
use Illuminate\Support\Facades\Gate;
use App\Services\FlutterOperationService;
use App\Http\Resources\Flutter\DebtorResource;
use App\Http\Resources\Flutter\ProductResource;
use App\Http\Requests\Flutter\SyncOperationsRequest;

class SyncController extends Controller
{
    public function __construct(private FlutterSyncService $sync, private FlutterOperationService $operations) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $snapshot = $this->sync->createSnapshot($request->user(), $request->attributes->get('flutter_store'), $request->attributes->get('flutter_device'));

        return response()->json(['data' => [
            'snapshot_id' => (string) $snapshot->id, 'watermark' => (int) $snapshot->watermark,
            'manifest'    => array_map('count', $snapshot->data),
            'stocks'      => \App\Models\Stock::withoutGlobalScope('current_store')->active()
                ->whereHas('stores', fn ($query) => $query->whereKey($request->attributes->get('flutter_store')->id))
                ->orderBy('id')->get(['id', 'name'])->map(fn ($stock): array => ['id' => (string) $stock->id, 'name' => $stock->name]),
            'server_time' => now()->toIso8601String(), 'expires_at' => $snapshot->expires_at->toIso8601String(), 'schema_version' => 1,
        ]]);
    }

    public function bootstrapData(Request $request, string $storeId, string $snapshotId): JsonResponse
    {
        $store    = $request->attributes->get('flutter_store');
        $snapshot = FlutterSyncSnapshot::query()->whereKey($snapshotId)->where('store_id', $store->id)
            ->where('user_id', $request->user()->id)->where('device_id', $request->attributes->get('flutter_device')->id)
            ->where('status', 'ready')->where('expires_at', '>', now())->firstOrFail();
        abort_unless($snapshot->permission_hash === $this->sync->permissionFingerprint($request->user()), 409, 'Ruxsatlar o‘zgargan. Qayta yuklang.');
        $entity = (string) $request->input('entity', 'products');
        abort_unless(in_array($entity, ['products', 'debtors', 'clients'], true), 422);
        if ($entity === 'products') {
            abort_unless(Gate::forUser($request->user())->allows('viewAny', Product::class) || $request->user()->can('View:Pos'), 403);
        } elseif ($entity === 'clients') {
            Gate::forUser($request->user())->authorize('View:Pos');
        } else {
            Gate::forUser($request->user())->authorize('viewAny', Debtor::class);
        }
        $limit  = min(500, max(1, (int) $request->input('limit', 100)));
        $cursor = max(0, (int) $request->input('cursor', 0));
        $items  = collect($snapshot->data[$entity] ?? [])->filter(fn (array $item): bool => (int) $item['id'] > $cursor)->values();
        $slice  = $items->take($limit);

        return response()->json(['data' => ['entity' => $entity, 'items' => $slice->all(),
            'next_cursor'                            => $slice->isNotEmpty() ? (string) $slice->last()['id'] : (string) $cursor,
            'has_more'                               => $items->count() > $limit]]);
    }

    public function delta(Request $request): JsonResponse
    {
        $store    = $request->attributes->get('flutter_store');
        $entities = [];
        if (Gate::forUser($request->user())->allows('viewAny', Product::class) || $request->user()->can('View:Pos')) {
            $entities[] = 'product';
        }
        if (Gate::forUser($request->user())->allows('viewAny', Debtor::class)) {
            $entities[] = 'debtor';
        }
        if ($request->user()->can('View:Pos')) {
            $entities[] = 'client';
        }
        abort_unless($entities, 403);
        $limit     = min(500, max(1, (int) $request->input('limit', 100)));
        $cursor    = max(0, (int) $request->input('cursor', 0));
        $revisions = FlutterSyncRevision::query()->where('store_id', $store->id)->whereIn('entity_type', $entities)->where('id', '>', $cursor)->orderBy('id')->limit($limit + 1)->get();
        $slice     = $revisions->take($limit);
        $products  = $this->sync->productsQuery($store->id)->whereIn('id', $slice->where('entity_type', 'product')->pluck('entity_id'))->get()->keyBy('id');
        $debtors   = Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)
            ->addSelect(['sync_revision' => FlutterSyncRevision::query()->selectRaw('MAX(revision)')->whereColumn('entity_id', 'debtors.id')->where('store_id', $store->id)->where('entity_type', 'debtor')])
            ->whereIn('id', $slice->where('entity_type', 'debtor')->pluck('entity_id'))->with(['client', 'transactions'])->get()->keyBy('id');
        $clients = \App\Models\Client::query()->whereIn('id', $slice->where('entity_type', 'client')->pluck('entity_id'))->get()->keyBy('id');
        $changes = $slice->map(function ($revision) use ($request, $products, $debtors, $clients): array {
            $model = match ($revision->entity_type) {
                'product' => $products->get($revision->entity_id), 'client' => $clients->get($revision->entity_id), default => $debtors->get($revision->entity_id)
            };
            $value = $model ? match ($revision->entity_type) {
                'product' => new ProductResource($model), 'client' => new \App\Http\Resources\Flutter\ClientResource($model), default => new DebtorResource($model)
            } : null;
            $value = $value?->resolve($request);

            return ['entity' => $revision->entity_type, 'id' => (string) $revision->entity_id,
                'revision'   => (string) $revision->revision, 'action' => $model ? 'upsert' : 'delete', 'value' => $value];
        });

        return response()->json(['data' => ['changes' => $changes,
            'next_cursor'                             => (string) ($slice->last()?->id ?? $cursor), 'has_more' => $revisions->count() > $limit]]);
    }

    public function operations(SyncOperationsRequest $request): JsonResponse
    {
        $results = [];
        foreach ($request->validated('operations') as $envelope) {
            $results[] = $this->operations->process(
                $request->user(),
                $request->attributes->get('flutter_store'),
                $request->attributes->get('flutter_device'),
                $envelope
            );
        }

        return response()->json(['data' => ['results' => $results]]);
    }

    public function showOperation(Request $request, string $storeId, string $operationId): JsonResponse
    {
        $operation = FlutterSyncOperation::query()->where('store_id', $request->attributes->get('flutter_store')->id)
            ->where('user_id', $request->user()->id)->where('device_id', $request->attributes->get('flutter_device')->id)
            ->where('operation_id', $operationId)->firstOrFail();

        return response()->json(['data' => ['operation_id' => $operation->operation_id, 'status' => $operation->status,
            'canonical_id'                                 => $operation->entity_id, 'response_data' => $operation->response_data]]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $conflicts = FlutterSyncConflict::query()->where('store_id', $request->attributes->get('flutter_store')->id)
            ->where('user_id', $request->user()->id)
            ->whereHas('operation', fn ($query) => $query->where('device_id', $request->attributes->get('flutter_device')->id))
            ->where('status', 'pending')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $conflicts->map(fn ($conflict): array => [
            'id'     => (string) $conflict->id, 'operation_id' => $conflict->operation_id, 'entity_type' => $conflict->entity_type,
            'reason' => $conflict->reason, 'created_at' => $conflict->created_at->toIso8601String(),
        ])]);
    }
}
