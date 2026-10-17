<?php

namespace App\Http\Controllers\Mobile;

use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\PurchaseItem;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Mobile\SearchRequest;
use App\Http\Resources\Mobile\ProductResource;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Mobile\CreateProductRequest;
use App\Http\Requests\Mobile\UpdateProductRequest;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function createOptions(): JsonResponse
    {
        Gate::authorize('create', Product::class);

        return response()->json(['stocks' => Stock::query()->active()->orderBy('name')->get(['id', 'name'])]);
    }

    public function generateBarcode(): JsonResponse
    {
        Gate::authorize('create', Product::class);
        do {
            $barcode = Helper::generateEAN13Barcode();
        } while (Product::withTrashed()->where('barcode', $barcode)->exists());

        return response()->json(['barcode' => $barcode]);
    }

    public function store(CreateProductRequest $request): ProductResource
    {
        $data = $request->validated();

        try {
            $product = (new Product)->getConnection()->transaction(function () use ($data): Product {
                $product = Product::query()->create(collect($data)->only(['store_id', 'name', 'barcode', 'type', 'initial_price', 'price'])->all());
                if ($product->isSizeBased()) {
                    foreach ($data['sizes'] as $row) {
                        $size = $product->sizes()->create(['size' => $row['size']]);
                        $size->productStocks()->createMany($row['stocks']);
                    }
                } else {
                    $product->productStocks()->createMany($data['stocks']);
                }

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barcode' => 'Bu shtrix-kod ushbu do‘konda mavjud.']);
        }

        return new ProductResource($this->products($request->user()->current_store_id)->findOrFail($product->id));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();

        try {
            $product->getConnection()->transaction(function () use ($data, $product): void {
                $product  = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
                $stockIds = Stock::query()->pluck('id')->all();
                $type     = $data['type'] ?? $product->type;
                if ($type !== $product->type) {
                    $hasOtherStocks = $product->productStocks()->whereNotIn('stock_id', $stockIds)->exists()
                        || $product->sizes()->where(fn (Builder $sizes): Builder => $sizes
                            ->whereHas('productStocks', fn (Builder $stocks): Builder => $stocks->whereNotIn('stock_id', $stockIds))
                            ->orWhereHas('stocks', fn (Builder $stocks): Builder => $stocks->whereNotIn('stock_id', $stockIds)))->exists();
                    if ($hasOtherStocks) {
                        throw ValidationException::withMessages(['type' => 'Boshqa ombordagi qoldiq sababli mahsulot turini almashtirib bo‘lmaydi.']);
                    }
                    $product->productStocks()->delete();
                }
                $product->update(collect($data)->only(['name', 'barcode', 'initial_price', 'price', 'type'])->all());
                if (array_key_exists('sizes', $data) || ($type !== Product::TYPE_SIZE && $product->wasChanged('type'))) {
                    $keptIds = [];
                    foreach ($data['sizes'] ?? [] as $row) {
                        $size = !empty($row['id'])
                            ? $product->sizes()->findOrFail($row['id'])
                            : $product->sizes()->make();
                        $size->fill(['size' => $row['size']])->save();
                        $keptIds[] = $size->id;
                        foreach ($row['stocks'] as $stock) {
                            $size->productStocks()->updateOrCreate(['stock_id' => $stock['stock_id']], ['quantity' => $stock['quantity']]);
                        }
                    }
                    foreach ($product->sizes()->whereNotIn('id', $keptIds)->get() as $size) {
                        if (SaleItem::withoutGlobalScopes()->where('product_size_id', $size->id)->exists()
                            || PurchaseItem::withoutGlobalScopes()->where('product_size_id', $size->id)->exists()) {
                            throw ValidationException::withMessages(['sizes' => 'Savdo yoki kirim tarixida ishlatilgan razmerni o‘chirib bo‘lmaydi. Qoldig‘ini 0 qiling yoki razmerni saqlab qoling.']);
                        }
                        if ($size->productStocks()->whereNotIn('stock_id', $stockIds)->exists() || $size->stocks()->whereNotIn('stock_id', $stockIds)->exists()) {
                            throw ValidationException::withMessages(['sizes' => 'Boshqa omborda qoldig‘i bor razmerni olib tashlab bo‘lmaydi.']);
                        }
                        $size->productStocks()->delete();
                        $size->stocks()->delete();
                        $size->delete();
                    }
                }
                foreach ($data['stocks'] ?? [] as $stock) {
                    $product->productStocks()->updateOrCreate(['stock_id' => $stock['stock_id']], ['quantity' => $stock['quantity']]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barcode' => 'Bu shtrix-kod ushbu do‘konda mavjud.']);
        }

        return new ProductResource($this->products($request->user()->current_store_id)->findOrFail($product->id));
    }

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

        $resource = new ProductResource($this->products($request->user()->current_store_id)->findOrFail($product->id));
        if ($request->user()->can('update', $product)) {
            $stocks = Stock::query()->orderBy('name')->get(['id', 'name', 'is_active']);
            $product->load(['productStocks', 'sizes.productStocks', 'sizes.stocks']);
            $resource->additional(['edit' => [
                'stocks'     => $stocks,
                'quantities' => $product->productStocks->whereIn('stock_id', $stocks->modelKeys())->map->only(['stock_id', 'quantity'])->values(),
                'sizes'      => $product->sizes->map(fn ($size): array => [
                    'id'     => $size->id,
                    'size'   => $size->size,
                    'stocks' => $stocks->map(fn ($stock): array => [
                        'stock_id' => $stock->id,
                        'quantity' => $size->productStocks->firstWhere('stock_id', $stock->id)?->quantity ?? $size->stocks->firstWhere('stock_id', $stock->id)?->quantity ?? 0,
                    ]),
                ]),
            ]]);
        }

        return $resource;
    }
}
