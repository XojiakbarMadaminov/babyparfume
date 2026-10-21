<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('flutter.enabled', true);

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Do‘kon B', 'address' => 'Samarqand', 'phone' => '+998902222222']);

    $this->stockA = Stock::query()->create(['name' => 'Ombor A', 'is_active' => true]);
    $this->stockA->stores()->attach($this->storeA);

    $this->stockB = Stock::query()->create(['name' => 'Ombor B', 'is_active' => true]);
    $this->stockB->stores()->attach($this->storeB);

    $this->user = User::factory()->create([
        'email'            => 'product_mgr@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach([$this->storeA->id, $this->storeB->id]);

    $role = Role::findOrCreate('admin', 'web');
    $permissions = [
        'ViewAny:Product',
        'View:Product',
        'Create:Product',
        'Update:Product',
        'Delete:Product',
        'view_mobile_sales_cost_price',
    ];
    foreach ($permissions as $p) {
        $perm = Permission::findOrCreate($p, 'web');
        $role->givePermissionTo($perm);
        $this->user->givePermissionTo($perm);
    }

    $loginResponse = $this->postJson('/api/flutter/v1/login', [
        'email'         => 'product_mgr@example.com',
        'password'      => 'password',
        'device_uuid'   => (string) Str::uuid(),
        'device_name'   => 'iPhone 15',
        'platform'      => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('lists products scoped to the requested store with stock quantities and canonical string numbers', function (): void {
    $productA = Product::query()->create([
        'store_id'      => $this->storeA->id,
        'name'          => 'Chanel Blue',
        'barcode'       => '4780001112223',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 50000,
        'price'         => 80000,
    ]);
    $productA->productStocks()->create(['stock_id' => $this->stockA->id, 'quantity' => 15]);

    $productB = Product::query()->create([
        'store_id'      => $this->storeB->id,
        'name'          => 'Dior Sauvage',
        'barcode'       => '4780009998887',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 60000,
        'price'         => 95000,
    ]);
    $productB->productStocks()->create(['stock_id' => $this->stockB->id, 'quantity' => 20]);

    // Store A query
    $respA = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/products")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $productA->id)
        ->assertJsonPath('data.0.name', 'Chanel Blue')
        ->assertJsonPath('data.0.price', '80000')
        ->assertJsonPath('data.0.initial_price', '50000')
        ->assertJsonPath('data.0.quantity', 15)
        ->assertJsonPath('data.0.currency', 'UZS')
        ->assertJsonPath('data.0.scale', 0);

    // Store B query
    $respB = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeB->id}/products")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $productB->id)
        ->assertJsonPath('data.0.name', 'Dior Sauvage')
        ->assertJsonPath('data.0.price', '95000')
        ->assertJsonPath('data.0.quantity', 20);
});

it('provides unique barcode generation and create options per store', function (): void {
    $optionsResp = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/products/create-options")
        ->assertOk()
        ->assertJsonPath('stocks.0.id', (string) $this->stockA->id);

    $barcodeResp = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/products/barcode")
        ->assertOk();

    $barcode = $barcodeResp->json('barcode');
    expect($barcode)->toBeString()->and(strlen($barcode))->toBe(13);
});

it('looks up a product by barcode within the store', function (): void {
    $product = Product::query()->create([
        'store_id'      => $this->storeA->id,
        'name'          => 'Versace Eros',
        'barcode'       => '4781234567890',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 70000,
        'price'         => 110000,
    ]);

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/products/lookup?barcode=4781234567890")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $product->id)
        ->assertJsonPath('data.name', 'Versace Eros')
        ->assertJsonPath('data.barcode', '4781234567890');

    // In Store B lookup should fail (404)
    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeB->id}/products/lookup?barcode=4781234567890")
        ->assertNotFound();
});

it('creates and updates a product in the given store', function (): void {
    $createPayload = [
        'name'          => 'Tom Ford Tobacco Vanille',
        'barcode'       => '4789999888877',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 150000,
        'price'         => 250000,
        'stocks'        => [
            ['stock_id' => $this->stockA->id, 'quantity' => 10],
        ],
    ];

    $createResp = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/products", $createPayload)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tom Ford Tobacco Vanille')
        ->assertJsonPath('data.price', '250000')
        ->assertJsonPath('data.quantity', 10);

    $productId = $createResp->json('data.id');

    // Update
    $updatePayload = [
        'name'          => 'Tom Ford Tobacco Vanille (Updated)',
        'barcode'       => '4789999888877',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 160000,
        'price'         => 270000,
        'stocks'        => [
            ['stock_id' => $this->stockA->id, 'quantity' => 12],
        ],
    ];

    $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/products/{$productId}", $updatePayload)
        ->assertOk()
        ->assertJsonPath('data.name', 'Tom Ford Tobacco Vanille (Updated)')
        ->assertJsonPath('data.price', '270000')
        ->assertJsonPath('data.quantity', 12);
});

it('deletes a product belonging to the store and rejects deleting products from other stores', function (): void {
    $productA = Product::query()->create([
        'store_id'      => $this->storeA->id,
        'name'          => 'To Delete',
        'barcode'       => '4780005554443',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 10000,
        'price'         => 20000,
    ]);

    // Deleting via store B fails with 404
    $this->withToken($this->token)
        ->deleteJson("/api/flutter/v1/stores/{$this->storeB->id}/products/{$productA->id}")
        ->assertNotFound();

    // Deleting via store A succeeds
    $this->withToken($this->token)
        ->deleteJson("/api/flutter/v1/stores/{$this->storeA->id}/products/{$productA->id}")
        ->assertOk();

    expect(Product::query()->find($productA->id))->toBeNull();
});
