<?php

namespace App\Http\Controllers\Mobile;

use App\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Mobile\SearchRequest;
use App\Http\Resources\Mobile\ProductResource;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    private function products(int $storeId): Builder
    {
        $stockInStore = fn (Builder $query): Builder => $query->whereHas('stock.stores', fn (Builder $stores): Builder => $stores->whereKey($storeId));

        return Product::query()->with([
            'media',
            'sizes' => fn (HasMany $sizes): HasMany => $sizes
                ->withCount(['productStocks as mobile_stock_count' => $stockInStore])
                ->withSum(['productStocks as mobile_quantity' => $stockInStore], 'quantity')
                ->withSum(['stocks as legacy_quantity' => $stockInStore], 'quantity'),
        ])->withSum(['productStocks as store_quantity' => $stockInStore], 'quantity');
    }

    public function index(SearchRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);
        $query = $this->products($request->user()->current_store_id);
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn (Builder $products): Builder => $products->where('name', 'like', '%' . $search . '%')->orWhere('barcode', 'like', '%' . $search . '%'));
        }

        return ProductResource::collection($query->orderBy('name')->paginate(20));
    }

    public function lookup(SearchRequest $request): ProductResource
    {
        Gate::authorize('viewAny', Product::class);
        abort_if(blank($request->input('barcode')), 422, 'Shtrix-kodni kiriting.');
        $product = $this->products($request->user()->current_store_id)->where('barcode', $request->input('barcode'))->firstOrFail();
        Gate::authorize('view', $product);

        return new ProductResource($product);
    }

    public function show(SearchRequest $request, Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($this->products($request->user()->current_store_id)->findOrFail($product->id));
    }
}
