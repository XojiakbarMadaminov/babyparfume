<?php

namespace App\Http\Controllers\Mobile;

use App\Models\Debtor;
use Illuminate\Http\JsonResponse;
use App\Services\MobileDebtService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Mobile\SearchRequest;
use App\Http\Resources\Mobile\DebtorResource;
use App\Http\Requests\Mobile\CreateDebtorRequest;
use App\Http\Requests\Mobile\UpdateDebtorRequest;
use App\Http\Requests\Mobile\DebtorTransactionRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DebtorController extends Controller
{
    public function index(SearchRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Debtor::class);
        $query = Debtor::query()->with('client');
        if ($request->input('filter', 'active') === 'overdue') {
            $query->overdue();
        } elseif ($request->input('filter', 'active') === 'active') {
            $query->stillInDebt();
        } elseif ($request->input('filter', 'active') === 'closed') {
            $query->zeroDebt();
        }
        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('client', fn (Builder $clients): Builder => $clients->whereLike('full_name', '%' . $search . '%')->orWhereLike('phone', '%' . $search . '%'));
        }
        $totals = Debtor::query()->stillInDebt()->selectRaw('currency, SUM(amount) as amount, COUNT(*) as count')->groupBy('currency')->get();

        return DebtorResource::collection($query->orderByDesc('updated_at')->orderByDesc('id')->paginate(20))->additional([
            'counts' => [
                'active'  => Debtor::query()->stillInDebt()->count(),
                'closed'  => Debtor::query()->zeroDebt()->count(),
                'overdue' => Debtor::query()->overdue()->count(),
            ],
            'summary' => $totals->map(fn (Debtor $total): array => ['currency' => $total->currency, 'amount' => (int) $total->amount, 'count' => (int) $total->count]),
        ]);
    }

    public function show(SearchRequest $request, Debtor $debtor): DebtorResource
    {
        Gate::authorize('view', $debtor);

        return new DebtorResource($debtor->load('client'));
    }

    public function transactions(SearchRequest $request, Debtor $debtor): JsonResponse
    {
        Gate::authorize('view', $debtor);

        return response()->json($debtor->transactions()->reorder()->orderByDesc('date')->orderByDesc('id')->paginate(20, ['id', 'type', 'amount', 'date', 'note']));
    }

    public function store(CreateDebtorRequest $request, MobileDebtService $service): DebtorResource
    {
        Gate::authorize('create', Debtor::class);

        return new DebtorResource($service->create($request->user(), $request->validated())->load('client'));
    }

    public function update(UpdateDebtorRequest $request, Debtor $debtor, MobileDebtService $service): DebtorResource
    {
        Gate::authorize('update', $debtor);

        return new DebtorResource($service->update($request->user(), $debtor, $request->validated())->load('client'));
    }

    public function transact(DebtorTransactionRequest $request, Debtor $debtor, MobileDebtService $service): DebtorResource
    {
        Gate::authorize('update', $debtor);

        return new DebtorResource($service->transact($request->user(), $debtor, $request->validated())->load('client'));
    }
}
