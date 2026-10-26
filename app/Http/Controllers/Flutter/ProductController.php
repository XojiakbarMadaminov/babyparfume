<?php

namespace App\Http\Controllers\Flutter;

use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use App\Services\ProductPhotoService;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Flutter\SearchRequest;
use App\Services\FlutterProductStockService;
use Illuminate\Validation\ValidationException;
use App\Http\Resources\Flutter\ProductResource;
use App\Http\Requests\Flutter\CreateProductRequest;
use App\Http\Requests\Flutter\UpdateProductRequest;
use App\Http\Requests\Flutter\ReceiveProductStockRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(SearchRequest $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);

        $store = $request->attributes->get('flutter_store');
        $query = $this->productsQuery((int) $store->id);

        if ($search = trim((string) $request->input('q'))) {
            $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function (Builder $products) use ($search, $words): void {
                $products->whereLike('barcode', '%' . $search . '%')
                    ->orWhere(function (Builder $nameQ) use ($words): void {
                        foreach ($words as $word) {
                            $nameQ->whereLike('name', '%' . $word . '%');
                        }
                    });
            });
        }

        $limit = (int) $request->input('limit', 20);

        return ProductResource::collection($query->orderBy('name')->paginate($limit));
    }

    public function createOptions(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Product::class);

        $store  = $request->attributes->get('flutter_store');
        $stocks = Stock::withoutGlobalScope('current_store')->active()->whereHas('stores', fn ($query) => $query->whereKey($store->id))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($stock): array => [
                'id'   => (string) $stock->id,
                'name' => (string) $stock->name,
            ]);

        return response()->json(['stocks' => $stocks]);
    }

    public function generateBarcode(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Product::class);

        $store = $request->attributes->get('flutter_store');

        do {
            $barcode = Helper::generateEAN13Barcode();
        } while (Product::withoutGlobalScope('current_store')
            ->withTrashed()
            ->where('store_id', $store->id)
            ->where('barcode', $barcode)
            ->exists());

        return response()->json(['barcode' => (string) $barcode]);
    }

    public function lookup(SearchRequest $request): ProductResource
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);

        $barcode = trim((string) $request->input('barcode'));
        abort_if(blank($barcode), 422, 'Shtrix-kodni kiriting.');

        $store   = $request->attributes->get('flutter_store');
        $product = $this->productsQuery((int) $store->id)->where('barcode', $barcode)->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $product);

        return new ProductResource($product);
    }

    public function show(Request $request, string $storeId, string $productId): ProductResource
    {
        $store   = $request->attributes->get('flutter_store');
        $product = $this->productsQuery((int) $store->id)->findOrFail($productId);

        Gate::forUser($request->user())->authorize('view', $product);

        $resource = new ProductResource($product);

        if (Gate::forUser($request->user())->allows('update', $product)) {
            $stocks = Stock::withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id))->orderBy('name')->get(['id', 'name', 'is_active']);
            $product->load(['productStocks', 'sizes.productStocks', 'sizes.stocks']);

            $resource->additional(['edit' => [
                'stocks' => $stocks->map(fn ($stock): array => [
                    'id'        => (string) $stock->id,
                    'name'      => (string) $stock->name,
                    'is_active' => (bool) $stock->is_active,
                ]),
                'quantities' => $product->productStocks
                    ->whereIn('stock_id', $stocks->modelKeys())
                    ->map(fn ($ps): array => [
                        'stock_id' => (string) $ps->stock_id,
                        'quantity' => (int) $ps->quantity,
                    ])->values(),
                'sizes' => $product->sizes->map(fn ($size): array => [
                    'id'     => (string) $size->id,
                    'size'   => (string) $size->size,
                    'stocks' => $stocks->map(fn ($stock): array => [
                        'stock_id' => (string) $stock->id,
                        'quantity' => (int) ($size->productStocks->firstWhere('stock_id', $stock->id)?->quantity
                            ?? $size->stocks->firstWhere('stock_id', $stock->id)?->quantity
                            ?? 0),
                    ]),
                ]),
            ]]);
        }

        return $resource;
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        $store            = $request->attributes->get('flutter_store');
        $data             = $request->validated();
        $data['store_id'] = $store->id;

        try {
            $product = (new Product)->getConnection()->transaction(function () use ($data): Product {
                $product = Product::withoutGlobalScope('current_store')->create(
                    collect($data)->only(['store_id', 'name', 'barcode', 'type', 'initial_price', 'price'])->all()
                );

                if ($product->isSizeBased()) {
                    foreach ($data['sizes'] ?? [] as $row) {
                        $size = $product->sizes()->create(['size' => $row['size']]);
                        $size->productStocks()->createMany($row['stocks'] ?? []);
                    }
                } else {
                    $product->productStocks()->createMany($data['stocks'] ?? []);
                }

                if (!empty($data['photo'])) {
                    app(ProductPhotoService::class)->replace($product, $data['photo']);
                }

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barcode' => 'Bu shtrix-kod ushbu do‘konda mavjud.']);
        }

        $fresh = $this->productsQuery((int) $store->id)->findOrFail($product->id);

        return (new ProductResource($fresh))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, string $storeId, string $productId): ProductResource
    {
        $store = $request->attributes->get('flutter_store');
        $data  = $request->validated();

        $product = Product::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($productId);

        Gate::forUser($request->user())->authorize('update', $product);

        try {
            $product->getConnection()->transaction(function () use ($data, $product, $store): void {
                $product  = $product->newQuery()->withoutGlobalScope('current_store')->lockForUpdate()->findOrFail($product->id);
                $stockIds = Stock::withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id))->pluck('id')->all();
                $type     = $data['type'] ?? $product->type;

                if ($type !== $product->type) {
                    $hasOtherStocks = $product->productStocks()->whereNotIn('stock_id', $stockIds)->exists()
                        || $product->sizes()->where(
                            fn (Builder $sizes): Builder => $sizes
                                ->whereHas('productStocks', fn (Builder $stocks): Builder => $stocks->whereNotIn('stock_id', $stockIds))
                                ->orWhereHas('stocks', fn (Builder $stocks): Builder => $stocks->whereNotIn('stock_id', $stockIds))
                        )->exists();

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

                        foreach ($row['stocks'] ?? [] as $stock) {
                            $size->productStocks()->updateOrCreate(
                                ['stock_id' => $stock['stock_id']],
                                ['quantity' => $stock['quantity']]
                            );
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
                    $product->productStocks()->updateOrCreate(
                        ['stock_id' => $stock['stock_id']],
                        ['quantity' => $stock['quantity']]
                    );
                }

                if (!empty($data['photo'])) {
                    app(ProductPhotoService::class)->replace($product, $data['photo']);
                } elseif ($data['remove_image'] ?? false) {
                    $product->clearMediaCollection(Product::IMAGE_COLLECTION);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barcode' => 'Bu shtrix-kod ushbu do‘konda mavjud.']);
        }

        return new ProductResource($this->productsQuery((int) $store->id)->findOrFail($product->id));
    }

    public function receiveStock(ReceiveProductStockRequest $request, string $storeId, string $productId, FlutterProductStockService $stocks): ProductResource
    {
        $store = $request->attributes->get('flutter_store');
        $stocks->receive($request->user(), $store, $request->attributes->get('flutter_device'), $productId, $request->validated());

        return new ProductResource($this->productsQuery((int) $store->id)->findOrFail($productId));
    }

    public function destroy(Request $request, string $storeId, string $productId): JsonResponse
    {
        $store   = $request->attributes->get('flutter_store');
        $product = Product::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($productId);

        Gate::forUser($request->user())->authorize('delete', $product);

        $product->delete();

        return response()->json(['message' => 'Mahsulot o‘chirildi.']);
    }

    private function productsQuery(int $storeId): Builder
    {
        return app(\App\Services\FlutterSyncService::class)->productsQuery($storeId);
    }
}
