<?php

namespace App\Http\Controllers\Flutter;

use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\FlutterSyncSnapshot;
use App\Models\FlutterSyncConflict;
use App\Models\FlutterSyncRevision;
use App\Models\FlutterSyncOperation;
use App\Services\FlutterSaleService;
use App\Services\FlutterSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use App\Services\FlutterDebtService;
use App\Http\Resources\Flutter\DebtorResource;
use App\Http\Resources\Flutter\ProductResource;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class SyncController extends Controller
{
    public function __construct(
        protected FlutterSyncService $syncService,
        protected FlutterDebtService $debtService,
        protected FlutterSaleService $saleService
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $store  = $request->attributes->get('flutter_store');
        $device = $request->attributes->get('flutter_device');
        $user   = $request->user();

        $snapshot = $this->syncService->createSnapshot($user, $store, $device);
        $manifest = $this->syncService->getManifestCounts($store);

        return response()->json([
            'data' => [
                'snapshot_id'    => (string) $snapshot->id,
                'watermark'      => (string) $snapshot->watermark,
                'manifest'       => $manifest,
                'server_time'    => now()->toIso8601String(),
                'expires_at'     => $snapshot->expires_at->toIso8601String(),
                'schema_version' => 1,
            ],
        ]);
    }

    public function bootstrapData(Request $request, string $storeId, string $snapshotId): JsonResponse
    {
        $store    = $request->attributes->get('flutter_store');
        $snapshot = FlutterSyncSnapshot::query()
            ->where('id', $snapshotId)
            ->where('store_id', $store->id)
            ->where('status', 'ready')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $entity = (string) $request->input('entity', 'products');
        $limit  = min(500, max(1, (int) $request->input('limit', 100)));
        $cursor = (int) $request->input('cursor', 0);

        if ($entity === 'debtors') {
            Gate::forUser($request->user())->authorize('viewAny', Debtor::class);

            $items = Debtor::withoutGlobalScope('current_store')
                ->where('store_id', $store->id)
                ->where('id', '>', $cursor)
                ->with('client')
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();

            $hasMore = $items->count() > $limit;
            $slice   = $hasMore ? $items->slice(0, $limit) : $items;
            $nextCursor = $slice->isNotEmpty() ? (string) $slice->last()->id : null;

            return response()->json([
                'data' => [
                    'entity'      => 'debtors',
                    'items'       => DebtorResource::collection($slice),
                    'next_cursor' => $nextCursor,
                    'has_more'    => $hasMore,
                ],
            ]);
        }

        // Default products
        Gate::forUser($request->user())->authorize('viewAny', Product::class);

        $stockInStore = fn ($query) => $query->whereHas('stock.stores', fn ($stores) => $stores->whereKey($store->id));

        $items = Product::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->where('id', '>', $cursor)
            ->with([
                'media',
                'sizes' => fn ($sizes) => $sizes
                    ->withCount(['productStocks as mobile_stock_count' => $stockInStore])
                    ->withSum(['productStocks as mobile_quantity' => $stockInStore], 'quantity')
                    ->withSum(['stocks as legacy_quantity' => $stockInStore], 'quantity'),
            ])
            ->withSum(['productStocks as store_quantity' => $stockInStore], 'quantity')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        $slice   = $hasMore ? $items->slice(0, $limit) : $items;
        $nextCursor = $slice->isNotEmpty() ? (string) $slice->last()->id : null;

        return response()->json([
            'data' => [
                'entity'      => 'products',
                'items'       => ProductResource::collection($slice),
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ]);
    }

    public function delta(Request $request): JsonResponse
    {
        $store  = $request->attributes->get('flutter_store');
        $limit  = min(500, max(1, (int) $request->input('limit', 100)));
        $cursor = (int) $request->input('cursor', 0);

        $revisions = FlutterSyncRevision::query()
            ->where('store_id', $store->id)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $revisions->count() > $limit;
        $slice   = $hasMore ? $revisions->slice(0, $limit) : $revisions;
        $nextCursor = $slice->isNotEmpty() ? (string) $slice->last()->id : (string) $cursor;

        $changes = $slice->map(fn ($rev): array => [
            'entity'   => (string) $rev->entity_type,
            'id'       => (string) $rev->entity_id,
            'revision' => (string) $rev->revision,
            'action'   => (string) $rev->action,
            'value'    => $rev->payload,
        ]);

        return response()->json([
            'data' => [
                'changes'     => $changes,
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        $store      = $request->attributes->get('flutter_store');
        $device     = $request->attributes->get('flutter_device');
        $user       = $request->user();
        $operations = (array) $request->input('operations', []);

        if (empty($operations)) {
            return response()->json(['data' => ['results' => []]]);
        }

        $results = [];

        foreach ($operations as $op) {
            $opId        = $op['operation_id'] ?? null;
            $opType      = $op['operation_type'] ?? '';
            $payload     = (array) ($op['payload'] ?? []);
            $payloadHash = $this->hashPayload($payload);
            $occurredAt  = !empty($op['occurred_at']) ? Carbon::parse($op['occurred_at']) : now();

            // 1. Idempotency Check
            $existing = FlutterSyncOperation::query()->where('operation_id', $opId)->first();
            if ($existing) {
                if ($existing->payload_hash !== $payloadHash) {
                    $results[] = [
                        'operation_id' => $opId,
                        'status'       => 'conflict',
                        'error'        => 'idempotency_mismatch',
                    ];
                    continue;
                }

                $results[] = [
                    'operation_id'        => $opId,
                    'status'              => $existing->status,
                    'canonical_id'        => $existing->entity_id,
                    'response_data'       => $existing->response_data,
                    'is_replay'           => true,
                ];
                continue;
            }

            // 2. Process Operation
            try {
                $status       = 'applied';
                $entityId     = $op['entity_id'] ?? null;
                $responseData = null;

                if ($opType === 'sale.create') {
                    if (!$user->can('view_pos') && !Gate::forUser($user)->allows('View:Pos')) {
                        throw new \Exception('POS uchun ruxsat berilmagan.');
                    }
                    $saleResult = $this->saleService->createSale($user, $store, $payload);
                    $entityId   = $saleResult['server_sale_id'];
                    $responseData = $saleResult;

                    $this->syncService->recordRevision($store->id, 'sale', $entityId, 'upsert', $saleResult);
                } elseif ($opType === 'debtor.create') {
                    Gate::forUser($user)->authorize('create', Debtor::class);
                    $debtor = $this->debtService->createDebtor($user, $store->id, $payload, $opId);
                    $entityId = (string) $debtor->id;
                    $responseData = ['debtor_id' => $entityId, 'amount' => (string) $debtor->amount];

                    $this->syncService->recordRevision($store->id, 'debtor', $entityId, 'upsert', $responseData);
                } elseif ($opType === 'debtor.transact') {
                    $debtorId = (int) ($op['entity_id'] ?? $payload['debtor_id']);
                    $debtor   = Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->findOrFail($debtorId);
                    Gate::forUser($user)->authorize('update', $debtor);

                    $transResult = $this->debtService->transact($user, $store->id, $debtorId, $payload, $opId);
                    $entityId    = (string) $transResult['debtor']->id;
                    $responseData = [
                        'debtor_id'      => $entityId,
                        'amount'         => (string) $transResult['debtor']->amount,
                        'transaction_id' => (string) $transResult['transaction']->id,
                    ];

                    $this->syncService->recordRevision($store->id, 'debtor', $entityId, 'upsert', $responseData);
                } else {
                    $status = 'rejected';
                    $responseData = ['error' => 'Noma’lum operatsiya turi: ' . $opType];
                }

                $record = FlutterSyncOperation::query()->create([
                    'operation_id'   => $opId,
                    'device_id'      => $device->id,
                    'user_id'        => $user->id,
                    'store_id'       => $store->id,
                    'entity_type'    => $op['entity_type'] ?? 'unknown',
                    'entity_id'      => $entityId,
                    'local_id'       => $op['local_id'] ?? null,
                    'operation_type' => $opType,
                    'base_revision'  => $op['base_revision'] ?? null,
                    'payload_hash'   => $payloadHash,
                    'payload'        => $payload,
                    'status'         => $status,
                    'response_data'  => $responseData,
                    'occurred_at'    => $occurredAt,
                    'applied_at'     => $status === 'applied' ? now() : null,
                ]);

                $results[] = [
                    'operation_id'  => $opId,
                    'status'        => $status,
                    'canonical_id'  => $entityId,
                    'response_data' => $responseData,
                    'is_replay'     => false,
                ];
            } catch (\Exception $e) {
                $record = FlutterSyncOperation::query()->create([
                    'operation_id'   => $opId,
                    'device_id'      => $device->id,
                    'user_id'        => $user->id,
                    'store_id'       => $store->id,
                    'entity_type'    => $op['entity_type'] ?? 'unknown',
                    'entity_id'      => $op['entity_id'] ?? null,
                    'local_id'       => $op['local_id'] ?? null,
                    'operation_type' => $opType,
                    'base_revision'  => $op['base_revision'] ?? null,
                    'payload_hash'   => $payloadHash,
                    'payload'        => $payload,
                    'status'         => 'conflict',
                    'response_data'  => ['error' => $e->getMessage()],
                    'occurred_at'    => $occurredAt,
                ]);

                FlutterSyncConflict::query()->create([
                    'operation_id' => $opId,
                    'store_id'     => $store->id,
                    'user_id'      => $user->id,
                    'entity_type'  => $op['entity_type'] ?? 'unknown',
                    'entity_id'    => $op['entity_id'] ?? null,
                    'reason'       => $e->getMessage(),
                    'server_state' => null,
                    'client_state' => $payload,
                    'status'       => 'pending',
                ]);

                $results[] = [
                    'operation_id' => $opId,
                    'status'       => 'conflict',
                    'error'        => $e->getMessage(),
                ];
            }
        }

        return response()->json(['data' => ['results' => $results]]);
    }

    public function showOperation(Request $request, string $storeId, string $operationId): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');
        $op    = FlutterSyncOperation::query()
            ->where('store_id', $store->id)
            ->where('operation_id', $operationId)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'operation_id'  => $op->operation_id,
                'status'        => $op->status,
                'canonical_id'  => $op->entity_id,
                'response_data' => $op->response_data,
                'applied_at'    => $op->applied_at?->toIso8601String(),
            ],
        ]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $store     = $request->attributes->get('flutter_store');
        $conflicts = FlutterSyncConflict::query()
            ->where('store_id', $store->id)
            ->where('status', 'pending')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $conflicts->map(fn ($c): array => [
                'id'           => (string) $c->id,
                'operation_id' => (string) $c->operation_id,
                'entity_type'  => $c->entity_type,
                'entity_id'    => $c->entity_id,
                'reason'       => $c->reason,
                'created_at'   => $c->created_at->toIso8601String(),
            ]),
        ]);
    }

    protected function hashPayload(array $payload): string
    {
        $normalize = function (&$item) use (&$normalize): void {
            if (is_array($item)) {
                ksort($item);
                foreach ($item as &$value) {
                    $normalize($value);
                }
            }
        };

        $copy = $payload;
        $normalize($copy);

        return hash('sha256', (string) json_encode($copy));
    }
}
