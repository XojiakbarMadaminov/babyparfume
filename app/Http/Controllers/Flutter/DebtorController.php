<?php

namespace App\Http\Controllers\Flutter;

use App\Models\Debtor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\FlutterDebtService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Flutter\SearchRequest;
use App\Http\Resources\Flutter\DebtorResource;
use App\Http\Requests\Flutter\CreateDebtorRequest;
use App\Http\Requests\Flutter\UpdateDebtorRequest;
use App\Http\Requests\Flutter\DebtorTransactionRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DebtorController extends Controller
{
    public function index(SearchRequest $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Debtor::class);

        $store = $request->attributes->get('flutter_store');
        $query = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->with('client');

        $filter = $request->input('filter', 'active');
        if ($filter === 'overdue') {
            $query->overdue();
        } elseif ($filter === 'active') {
            $query->stillInDebt();
        } elseif ($filter === 'closed') {
            $query->zeroDebt();
        }

        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('client', fn (Builder $clients): Builder => $clients
                ->whereLike('full_name', '%' . $search . '%')
                ->orWhereLike('phone', '%' . $search . '%')
            );
        }

        $baseStoreDebtors = fn (): Builder => Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id);

        $totals = $baseStoreDebtors()
            ->stillInDebt()
            ->selectRaw('currency, SUM(amount) as amount, COUNT(*) as count')
            ->groupBy('currency')
            ->get();

        $limit = (int) $request->input('limit', 20);

        return DebtorResource::collection(
            $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($limit)
        )->additional([
            'counts' => [
                'active'  => $baseStoreDebtors()->stillInDebt()->count(),
                'closed'  => $baseStoreDebtors()->zeroDebt()->count(),
                'overdue' => $baseStoreDebtors()->overdue()->count(),
            ],
            'summary' => $totals->map(fn (Debtor $total): array => [
                'currency' => (string) ($total->currency ?: 'UZS'),
                'amount'   => (string) (int) $total->amount,
                'count'    => (int) $total->count,
            ]),
        ]);
    }

    public function show(Request $request, string $storeId, string $debtorId): DebtorResource
    {
        $store  = $request->attributes->get('flutter_store');
        $debtor = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($debtorId);

        Gate::forUser($request->user())->authorize('view', $debtor);

        return new DebtorResource($debtor->load('client'));
    }

    public function transactions(SearchRequest $request, string $storeId, string $debtorId): JsonResponse
    {
        $store  = $request->attributes->get('flutter_store');
        $debtor = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($debtorId);

        Gate::forUser($request->user())->authorize('view', $debtor);

        $limit = (int) $request->input('limit', 20);

        $paginator = $debtor->transactions()
            ->reorder()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($limit, ['id', 'type', 'amount', 'date', 'note']);

        $paginator->getCollection()->transform(fn ($tx): array => [
            'id'       => (string) $tx->id,
            'type'     => (string) $tx->type,
            'amount'   => (string) (int) $tx->amount,
            'currency' => 'UZS',
            'scale'    => 0,
            'date'     => (string) $tx->date,
            'note'     => $tx->note ? (string) $tx->note : null,
        ]);

        return response()->json($paginator);
    }

    public function store(CreateDebtorRequest $request, FlutterDebtService $service): JsonResponse
    {
        $store  = $request->attributes->get('flutter_store');
        $debtor = $service->create($request->user(), $store, $request->validated());

        $status = $debtor->wasRecentlyCreated ? 201 : 200;

        return (new DebtorResource($debtor->load('client')))->response()->setStatusCode($status);
    }

    public function update(UpdateDebtorRequest $request, string $storeId, string $debtorId, FlutterDebtService $service): DebtorResource
    {
        $store  = $request->attributes->get('flutter_store');
        $debtor = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($debtorId);

        Gate::forUser($request->user())->authorize('update', $debtor);

        $debtor = $service->update($request->user(), $store, $debtor, $request->validated());

        return new DebtorResource($debtor->load('client'));
    }

    public function transact(DebtorTransactionRequest $request, string $storeId, string $debtorId, FlutterDebtService $service): DebtorResource
    {
        $store  = $request->attributes->get('flutter_store');
        $debtor = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($debtorId);

        Gate::forUser($request->user())->authorize('update', $debtor);

        $debtor = $service->transact($request->user(), $store, $debtor, $request->validated());

        return new DebtorResource($debtor->load('client'));
    }
}
