<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->store = Store::query()->create(['name' => 'Mobil', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['Create:Product', 'View:Product', 'ViewAny:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->stock = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->payload = [
        'store_id' => $this->store->id, 'name' => 'Mobil mahsulot', 'barcode' => '04607006670808',
        'type'     => 'package', 'initial_price' => 20000, 'price' => 25000,
        'stocks'   => [['stock_id' => $this->stock->id, 'quantity' => 12]],
    ];
});

it('requires authentication and creation permission for product creation endpoints', function (): void {
    $this->user->revokePermissionTo('Create:Product');
    $this->getJson('/mobile/api/v1/products/create-options')->assertForbidden();
    $this->getJson('/mobile/api/v1/products/barcode')->assertForbidden();
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertForbidden();
    $this->getJson('/mobile/api/v1/session')->assertJsonPath('permissions.create_product', false);
    auth()->logout();
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertUnauthorized();
    $this->getJson('/mobile/api/v1/products/create-options')->assertUnauthorized();
    $this->getJson('/mobile/api/v1/products/barcode')->assertUnauthorized();
});

it('returns only active stocks belonging to the selected store', function (): void {
    $inactive = Stock::query()->create(['name' => 'Faol emas', 'is_active' => false]);
    $inactive->stores()->attach($this->store);
    Stock::query()->create(['name' => 'Begona ombor', 'is_active' => true]);
    $this->getJson('/mobile/api/v1/products/create-options')->assertOk()->assertJsonCount(1, 'stocks')->assertJsonPath('stocks.0.id', $this->stock->id);
    $this->getJson('/mobile/api/v1/session')->assertJsonPath('permissions.create_product', true);
});

it('generates a numeric EAN13 using the web checksum format without creating a product', function (): void {
    $barcode = $this->getJson('/mobile/api/v1/products/barcode')->assertOk()->json('barcode');
    expect($barcode)->toMatch('/^[0-9]{13}$/');
    $sum = 0;
    foreach (str_split(substr($barcode, 0, 12)) as $index => $digit) {
        $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
    }
    expect((int) $barcode[12])->toBe((10 - $sum % 10) % 10);
    $this->assertDatabaseCount('products', 0);
});

it('creates a package product with the exact barcode and current store stock', function (): void {
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertSuccessful()
        ->assertJsonPath('data.barcode', $this->payload['barcode'])->assertJsonPath('data.quantity', 12);
    $this->assertDatabaseHas('products', ['barcode' => $this->payload['barcode'], 'store_id' => $this->store->id, 'price' => 25000]);
    $product = Product::query()->firstOrFail();
    expect($product->productStocks()->first()->quantity)->toBe(12);
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('barcode');
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('product_stocks', 1);
});

it('creates size based stock rows without mixing package quantities', function (): void {
    $payload = array_replace($this->payload, ['type' => 'size', 'sizes' => [
        ['size' => '38', 'stocks' => [['stock_id' => $this->stock->id, 'quantity' => 3]]],
        ['size' => '39', 'stocks' => [['stock_id' => $this->stock->id, 'quantity' => 5]]],
    ]]);
    $this->postJson('/mobile/api/v1/products', $payload)->assertSuccessful()->assertJsonPath('data.quantity', 8);
    $product = Product::query()->firstOrFail();
    expect($product->sizes()->count())->toBe(2)->and($product->productStocks()->count())->toBe(0);
    $this->assertDatabaseCount('product_stocks', 2);
});

it('rejects duplicate stock rows within a size', function (): void {
    $payload = array_replace($this->payload, ['type' => 'size', 'sizes' => [
        ['size' => '38', 'stocks' => [$this->payload['stocks'][0], $this->payload['stocks'][0]]],
    ]]);
    $this->postJson('/mobile/api/v1/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.0.stocks');
    $this->assertDatabaseCount('products', 0);
});

it('rejects invalid product input before writing', function (string $field, mixed $value): void {
    $payload = $this->payload;
    data_set($payload, $field, $value);
    $this->postJson('/mobile/api/v1/products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('products', 0);
})->with([
    ['name', ''], ['barcode', '12ABC'], ['barcode', ''], ['price', -1], ['initial_price', 1.5],
    ['type', 'other'], ['stocks.0.quantity', -1], ['stocks.0.quantity', 1.5], ['store_id', 999999],
]);

it('rejects foreign inactive or deleted stocks for both product types', function (string $state, string $type): void {
    $stock = Stock::query()->create(['name' => 'Cheklangan', 'is_active' => $state !== 'inactive']);
    if ($state !== 'foreign') {
        $stock->stores()->attach($this->store);
    }
    if ($state === 'deleted') {
        $stock->delete();
    }
    $payload = array_replace($this->payload, ['type' => $type, 'stocks' => [['stock_id' => $stock->id, 'quantity' => 1]]]);
    $field   = 'stocks.0.stock_id';
    if ($type === 'size') {
        $payload['sizes'] = [['size' => 38, 'stocks' => $payload['stocks']]];
        $field            = 'sizes.0.stocks.0.stock_id';
    }
    $this->postJson('/mobile/api/v1/products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('products', 0);
})->with(['foreign', 'inactive', 'deleted'])->with(['package', 'size']);

it('allows the same barcode in another store but not a soft deleted duplicate in this store', function (): void {
    $other = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    Product::factory()->create(['store_id' => $other->id, 'barcode' => $this->payload['barcode']]);
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertSuccessful();
    Product::query()->firstOrFail()->delete();
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('barcode');
});

it('rejects stale store tabs and unassigned stores', function (): void {
    $this->withHeader('X-Mobile-Store', '999999')->postJson('/mobile/api/v1/products', $this->payload)->assertConflict();
    $this->user->stores()->detach();
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertForbidden();
    $this->assertDatabaseCount('products', 0);
});

it('allows an empty stock list when no stock is selected', function (): void {
    $this->payload['stocks']        = [];
    $this->payload['initial_price'] = 0;
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertSuccessful()->assertJsonPath('data.quantity', 0);
});

it('rolls back the product if saving stock fails', function (): void {
    $event = 'eloquent.creating: ' . ProductStock::class;
    Event::listen($event, function (): void {
        throw new RuntimeException('stock failed');
    });
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->postJson('/mobile/api/v1/products', $this->payload))->toThrow(RuntimeException::class, 'stock failed');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_stocks', 0);
    } finally {
        Event::forget($event);
    }
});
