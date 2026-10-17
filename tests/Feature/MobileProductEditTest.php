<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductStock;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = Store::query()->create(['name' => 'Mobil', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['View:Product', 'ViewAny:Product', 'Update:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->product = Product::factory()->create(['store_id' => $this->store->id, 'type' => 'package', 'barcode' => '1234567890123', 'initial_price' => 20000, 'price' => 25000]);
    $stock         = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $stock->stores()->attach($this->store);
    $this->inventory = ProductStock::query()->create(['stock_id' => $stock->id, 'product_id' => $this->product->id, 'quantity' => 7]);
    $this->payload   = ['store_id' => $this->store->id, 'name' => 'Yangilangan mahsulot', 'barcode' => $this->product->barcode, 'initial_price' => 21000, 'price' => 28000];
});

it('returns edit capability and existing values only to permitted users', function (): void {
    $this->getJson('/mobile/api/v1/products/' . $this->product->id)->assertOk()
        ->assertJsonPath('data.can_update', true)->assertJsonPath('data.initial_price', 20000)
        ->assertJsonPath('edit.quantities.0.quantity', 7)->assertJsonCount(1, 'edit.stocks');
    $this->user->revokePermissionTo('Update:Product');
    $this->getJson('/mobile/api/v1/products/' . $this->product->id)->assertOk()
        ->assertJsonPath('data.can_update', false)->assertJsonMissingPath('data.initial_price')->assertJsonMissingPath('edit');
});

it('shows product cost to permitted viewers without granting edit access', function (int $cost): void {
    $this->user->revokePermissionTo('Update:Product');
    $this->user->givePermissionTo(Permission::findOrCreate('view_mobile_sales_cost_price', 'web'));
    $this->product->update(['initial_price' => $cost]);

    $this->getJson('/mobile/api/v1/products/' . $this->product->id)->assertOk()
        ->assertJsonPath('data.initial_price', $cost)
        ->assertJsonPath('data.can_update', false)
        ->assertJsonMissingPath('edit');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $this->payload)->assertForbidden();

    $this->user->revokePermissionTo('view_mobile_sales_cost_price');
    $this->getJson('/mobile/api/v1/products/' . $this->product->id)->assertOk()->assertJsonMissingPath('data.initial_price');
})->with([0, 20000]);

it('updates product details without changing inventory or product type', function (): void {
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $this->payload)->assertOk()
        ->assertJsonPath('data.name', 'Yangilangan mahsulot')->assertJsonPath('data.price', 28000)
        ->assertJsonPath('data.quantity', 7);
    expect($this->product->fresh()->initial_price)->toEqual(21000)
        ->and($this->product->fresh()->type)->toBe('package')
        ->and($this->inventory->fresh()->quantity)->toEqual(7);
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('product_stocks', 1);
});

it('requires update permission and authentication', function (): void {
    $this->user->revokePermissionTo('Update:Product');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $this->payload)->assertForbidden();
    auth()->logout();
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $this->payload)->assertUnauthorized();
});

it('rejects another product barcode while accepting the current barcode', function (): void {
    Product::factory()->create(['store_id' => $this->store->id, 'barcode' => '9999999999999']);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, ['barcode' => '9999999999999']))
        ->assertUnprocessable()->assertJsonValidationErrors('barcode');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $this->payload)->assertOk();
});

it('does not allow editing another store or invalid inventory fields', function (): void {
    $other        = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $otherProduct = Product::factory()->create(['store_id' => $other->id]);
    $this->patchJson('/mobile/api/v1/products/' . $otherProduct->id, $this->payload)->assertNotFound();
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, ['store_id' => $other->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('store_id');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'package', 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => -1]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['stocks.0.quantity']);
    expect($this->inventory->fresh()->quantity)->toEqual(7);
});

it('updates package inventory including inactive stocks without duplicate rows', function (): void {
    Stock::findOrFail($this->inventory->stock_id)->update(['is_active' => false]);
    $payload = array_replace($this->payload, ['type' => 'package', 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 13]]]);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $payload)->assertOk()->assertJsonPath('data.quantity', 13);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, $payload)->assertOk();
    expect($this->inventory->fresh()->quantity)->toEqual(13);
    $this->assertDatabaseCount('product_stocks', 1);
});

it('converts package products to sizes and back without double counting stock', function (): void {
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [
            ['size' => 38, 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 4]]],
            ['size' => 40, 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 6]]],
        ],
    ]))->assertOk()->assertJsonPath('data.type', 'size')->assertJsonPath('data.quantity', 10);
    expect($this->product->productStocks()->count())->toBe(0);
    $this->getJson('/mobile/api/v1/products/' . $this->product->id)->assertOk()->assertJsonCount(2, 'edit.sizes');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'package', 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 8]],
    ]))->assertOk()->assertJsonPath('data.type', 'package')->assertJsonPath('data.quantity', 8);
    expect($this->product->sizes()->count())->toBe(0);
    $this->assertDatabaseCount('product_stocks', 1);
});

it('edits size values and quantities while preserving ids and removing omitted sizes', function (): void {
    $this->inventory->delete();
    $this->product->update(['type' => 'size']);
    $size      = $this->product->sizes()->create(['size' => '38']);
    $inventory = $size->productStocks()->create(['stock_id' => $this->inventory->stock_id, 'quantity' => 3]);
    $removed   = $this->product->sizes()->create(['size' => '40']);
    $removed->productStocks()->create(['stock_id' => $this->inventory->stock_id, 'quantity' => 2]);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [
            ['id' => $size->id, 'size' => 39, 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 5]]],
            ['size' => 42, 'stocks' => [['stock_id' => $this->inventory->stock_id, 'quantity' => 1]]],
        ],
    ]))->assertOk()->assertJsonPath('data.quantity', 6);
    expect($size->fresh()->size)->toBe('39')->and($inventory->fresh()->quantity)->toEqual(5)
        ->and($removed->fresh())->toBeNull();
});

it('rejects foreign stocks foreign size ids and duplicate sizes or stock rows', function (): void {
    $foreignStock = Stock::query()->create(['name' => 'Begona', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['store_id' => $this->store->id, 'type' => 'size']);
    $foreignSize  = $otherProduct->sizes()->create(['size' => 38]);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'package', 'stocks' => [['stock_id' => $foreignStock->id, 'quantity' => 5]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('stocks.0.stock_id');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [['id' => $foreignSize->id, 'size' => 38, 'stocks' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('sizes.0.id');
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [['size' => 38, 'stocks' => []], ['size' => 38, 'stocks' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('sizes.0.size');
    $stockRow = ['stock_id' => $this->inventory->stock_id, 'quantity' => 5];
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [['size' => 38, 'stocks' => [$stockRow, $stockRow]]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('sizes.0.stocks');
    expect($this->inventory->fresh()->quantity)->toEqual(7);
});

it('rolls back edits when removing a size would affect another store stock', function (): void {
    $foreignStock = Stock::query()->create(['name' => 'Begona', 'is_active' => true]);
    $this->inventory->delete();
    $this->product->update(['type' => 'size']);
    $size      = $this->product->sizes()->create(['size' => 38]);
    $inventory = $size->productStocks()->create(['stock_id' => $foreignStock->id, 'quantity' => 3]);
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => 'size', 'sizes' => [['size' => 40, 'stocks' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('sizes');
    expect($this->product->fresh()->name)->toBe($this->product->name)
        ->and($this->product->sizes()->count())->toBe(1)
        ->and($inventory->fresh()->quantity)->toEqual(3);
});

it('validates names barcode and prices before saving', function (): void {
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'name' => '', 'barcode' => 'abc', 'initial_price' => -1, 'price' => -5,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'barcode', 'initial_price', 'price']);
    expect($this->product->fresh()->price)->toEqual(25000);
});

it('preserves sales and purchase history when deleting sizes or changing type', function (string $history, string $type): void {
    $this->inventory->delete();
    $this->product->update(['type' => 'size']);
    $size      = $this->product->sizes()->create(['size' => 38]);
    $inventory = $size->productStocks()->create(['stock_id' => $this->inventory->stock_id, 'quantity' => 3]);
    if ($history === 'sale') {
        $sale = App\Models\Sale::withoutEvents(fn () => App\Models\Sale::query()->create(['store_id' => $this->store->id, 'cart_id' => 1]));
        $item = App\Models\SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $this->product->id, 'product_size_id' => $size->id, 'stock_id' => $this->inventory->stock_id, 'quantity' => 1]);
    } else {
        $supplier = App\Models\Supplier::factory()->create();
        $purchase = App\Models\Purchase::withoutEvents(fn () => App\Models\Purchase::query()->create(['store_id' => $this->store->id, 'supplier_id' => $supplier->id, 'purchase_date' => today()]));
        $item     = App\Models\PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'product_size_id' => $size->id, 'stock_id' => $this->inventory->stock_id, 'quantity' => 1]);
    }
    $this->patchJson('/mobile/api/v1/products/' . $this->product->id, array_replace($this->payload, [
        'type' => $type, 'stocks' => [], 'sizes' => [['size' => 40, 'stocks' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('sizes');
    expect($item->fresh())->not->toBeNull()
        ->and($size->fresh())->not->toBeNull()
        ->and($inventory->fresh()->quantity)->toEqual(3)
        ->and($this->product->fresh()->type)->toBe('size');
})->with([
    'sale size removal'        => ['sale', 'size'],
    'sale type conversion'     => ['sale', 'package'],
    'purchase size removal'    => ['purchase', 'size'],
    'purchase type conversion' => ['purchase', 'package'],
]);
