<?php

namespace App\Services;

use App\Models\Debtor;
use App\Models\Product;
use App\Models\McpOperation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class McpReadService
{
    public function __construct(private McpAccess $access, private MobileSalesStatisticsService $statistics) {}

    /** @return array<string, mixed> */
    public function account(): array
    {
        $connection = $this->access->connection();
        $user       = $this->access->user();

        return [
            'name'             => $user->name, 'date' => now()->toDateString(), 'timezone' => config('app.timezone'),
            'default_store_id' => $connection->default_store_id,
            'stores'           => $user->stores()->whereKey($connection->store_ids)->get(['stores.id', 'stores.name'])->toArray(),
            'permissions'      => $connection->permissions,
            'writes_enabled'   => (bool) config('mcp.writes_enabled'),
            'settings_url'     => route('mcp.connections.index'),
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function products(array $input): array
    {
        [$store, $data] = $this->context('products:read', $input);
        $user           = $this->access->user();
        Gate::forUser($user)->authorize('viewAny', Product::class);
        $stockScope = fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stock', fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id)));
        $query      = Product::withoutGlobalScope('current_store')->where(function ($query) use ($store): void {
            $query->where('store_id', $store->id)
                ->orWhereHas('productStocks', fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stock', fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id))))
                ->orWhereHas('sizes.productStocks', fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stock', fn ($query) => $query->withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id))));
        });
        if (!empty($data['search'])) {
            $search = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($query) => $query->whereLike('name', $search)->orWhereLike('barcode', $search));
        }
        $products = $query->withSum(['productStocks as mcp_quantity' => $stockScope], 'quantity')
            ->with(['sizes' => fn ($query) => $query->withoutGlobalScope('current_store')
                ->withCount(['productStocks as mcp_stock_count' => $stockScope])
                ->withSum(['productStocks as mcp_quantity' => $stockScope], 'quantity')
                ->withSum(['stocks as mcp_legacy_quantity' => $stockScope], 'quantity')])
            ->orderBy('id')->simplePaginate($data['per_page'], ['*'], 'page', $data['page']);
        $rows = $products->getCollection()->map(function (Product $product) use ($user): array {
            $row = [
                'id'       => $product->id, 'name' => $product->name, 'barcode' => $product->barcode,
                'price'    => (string) $product->price, 'currency' => 'UZS',
                'quantity' => $product->isSizeBased() ? (int) $product->sizes->sum(fn ($size) => $size->mcp_stock_count ? $size->mcp_quantity : $size->mcp_legacy_quantity) : (int) $product->mcp_quantity,
                'sizes'    => $product->sizes->map(fn ($size): array => ['id' => $size->id, 'size' => $size->size, 'quantity' => (int) ($size->mcp_stock_count ? $size->mcp_quantity : $size->mcp_legacy_quantity)])->all(),
            ];
            if ($user->can('view_mobile_sales_cost_price')) {
                $row['initial_price'] = (string) $product->initial_price;
            }

            return $row;
        });

        return ['store_id' => $store->id, 'data' => $rows->all(), 'page' => $data['page'], 'has_more' => $products->hasMorePages()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function debtors(array $input): array
    {
        [$store, $data] = $this->context('debts:read', $input, ['status' => ['nullable', Rule::in(['active', 'paid', 'overdue'])]]);
        Gate::forUser($this->access->user())->authorize('viewAny', Debtor::class);
        $query = Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->with('client');
        if (!empty($data['search'])) {
            $search = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->whereHas('client', fn ($query) => $query->whereLike('full_name', $search)->orWhereLike('phone', $search));
        }
        match ($data['status'] ?? null) {
            'active'  => $query->where('amount', '>', 0),
            'paid'    => $query->where('amount', '<=', 0),
            'overdue' => $query->where('amount', '>', 0)->where('date', '<', now()->subDays(20)->toDateString()),
            default   => null,
        };
        $results = $query->orderByDesc('amount')->orderBy('id')->simplePaginate($data['per_page'], ['*'], 'page', $data['page']);

        return ['store_id' => $store->id, 'data' => $results->getCollection()->map(fn ($debtor): array => $this->debtorData($debtor))->all(), 'page' => $data['page'], 'has_more' => $results->hasMorePages()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function debtor(array $input): array
    {
        [$store, $data] = $this->context('debts:read', $input, ['debtor_id' => ['required', 'integer', 'min:1']]);
        $debtor         = $this->access->debtor($store, $data['debtor_id']);
        Gate::forUser($this->access->user())->authorize('view', $debtor);

        return ['store_id' => $store->id, ...$this->debtorData($debtor)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function transactions(array $input): array
    {
        [$store, $data] = $this->context('debts:read', $input, ['debtor_id' => ['required', 'integer', 'min:1']]);
        $debtor         = $this->access->debtor($store, $data['debtor_id']);
        Gate::forUser($this->access->user())->authorize('view', $debtor);
        $results = $debtor->transactions()->reorder('id', 'desc')->simplePaginate($data['per_page'], ['id', 'type', 'amount', 'date', 'note'], 'page', $data['page']);

        return ['store_id' => $store->id, 'debtor_id' => $debtor->id,
            'data'         => $results->getCollection()->map(fn ($transaction): array => $transaction->only(['id', 'type', 'amount', 'date', 'note']))->all(),
            'page'         => $data['page'], 'has_more' => $results->hasMorePages()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sales(array $input): array
    {
        [$store, $data] = $this->context('sales:read', $input, [
            'start' => ['required', 'date_format:Y-m-d', 'before_or_equal:end'],
            'end'   => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $user = $this->access->user();
        abort_unless($user->can('View:Pos') && $user->can('view_mobile_sales_statistics'), 403);
        abort_if(Carbon::parse($data['start'])->diffInDays(Carbon::parse($data['end'])) > 366, 422);
        $report = $this->statistics->report($store->id, ['period' => $data['start'] === $data['end'] ? 'today' : 'range', 'start' => $data['start'], 'end' => $data['end']], true, true);
        if (!$user->can('view_mobile_sales_cost_price')) {
            unset($report['profit'], $report['marginPercent']);
        }

        return ['store_id' => $store->id, ...$report];
    }

    /** @return array<string, mixed> */
    public function operation(string $id): array
    {
        Validator::validate(['id' => $id], ['id' => ['required', 'uuid']]);
        $connection = $this->access->connection();
        $user       = $this->access->user();
        $this->access->permission($connection, $user, 'debts:write');
        $operation = $connection->operations()->with('deliveries')->findOrFail($id);
        $this->access->store($connection, $user, (int) $operation->store_id);
        $debtor = $operation->type === 'create' ? null : $this->access->debtor($operation->store, (int) $operation->payload['debtor_id']);
        $this->access->authorizeDebt($user, $operation->type, $debtor);

        return $this->operationData($operation);
    }

    /** @return array<string, mixed> */
    public function operationData(McpOperation $operation): array
    {
        return [
            'id'            => $operation->id, 'store_id' => $operation->store_id, 'type' => $operation->type,
            'status'        => $operation->status === 'pending' && $operation->expires_at->isPast() ? 'expired' : $operation->status,
            'expires_at'    => $operation->expires_at->toIso8601String(), 'result' => $operation->result,
            'approval_url'  => route('mcp.operations.show', $operation->id),
            'notifications' => $operation->deliveries->map(fn ($delivery): array => ['channel' => $delivery->channel, 'status' => $delivery->status])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function debtorData(Debtor $debtor): array
    {
        return ['id' => $debtor->id, 'full_name' => $debtor->client?->full_name, 'phone' => $debtor->client?->phone,
            'amount' => (string) $debtor->amount, 'currency' => $debtor->currency, 'date' => $debtor->date, 'note' => $debtor->note];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $extraRules
     * @return array{0: \App\Models\Store, 1: array<string, mixed>}
     */
    private function context(string $permission, array $input, array $extraRules = []): array
    {
        $connection = $this->access->connection();
        $user       = $this->access->user();
        $this->access->permission($connection, $user, $permission);
        $data = Validator::validate($input, [
            'store_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1', 'max:1000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ...$extraRules,
        ]);
        $data += ['page' => 1, 'per_page' => 20];

        return [$this->access->store($connection, $user, isset($data['store_id']) ? (int) $data['store_id'] : null), $data];
    }
}
