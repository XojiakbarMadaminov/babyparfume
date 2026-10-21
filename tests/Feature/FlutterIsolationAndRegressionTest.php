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

    $this->store1 = Store::query()->create(['name' => 'Web Store 1', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->store2 = Store::query()->create(['name' => 'Flutter Store 2', 'address' => 'Samarqand', 'phone' => '+998902222222']);

    $this->stock1 = Stock::query()->create(['name' => 'Ombor 1', 'is_active' => true]);
    $this->stock1->stores()->attach($this->store1);

    $this->stock2 = Stock::query()->create(['name' => 'Ombor 2', 'is_active' => true]);
    $this->stock2->stores()->attach($this->store2);

    $this->user = User::factory()->create([
        'email'            => 'hybrid_user@example.com',
        'current_store_id' => $this->store1->id,
    ]);
    $this->user->stores()->attach([$this->store1->id, $this->store2->id]);

    $role = Role::findOrCreate('admin', 'web');
    $permissions = [
        'view_pos',
        'View:Pos',
        'view_mobile_sales_statistics',
        'ViewAny:Product',
        'View:Product',
        'Create:Product',
        'Update:Product',
        'Delete:Product',
        'ViewAny:Debtor',
        'View:Debtor',
        'Create:Debtor',
        'Update:Debtor',
        'Delete:Debtor',
    ];
    foreach ($permissions as $p) {
        $perm = Permission::findOrCreate($p, 'web');
        $role->givePermissionTo($perm);
        $this->user->givePermissionTo($perm);
    }

    $loginResponse = $this->postJson('/api/flutter/v1/login', [
        'email'         => 'hybrid_user@example.com',
        'password'      => 'password',
        'device_uuid'   => (string) Str::uuid(),
        'device_name'   => 'iPhone 15',
        'platform'      => 'ios',
    ])->assertCreated();

    $this->flutterToken = $loginResponse->json('data.token');
});

it('verifies that flutter API requests do not mutate user current_store_id or interfere with web sessions', function (): void {
    // 1. Act as user on web with current_store_id = store 1
    $this->actingAs($this->user, 'web');
    expect($this->user->fresh()->current_store_id)->toBe($this->store1->id);

    // 2. Perform operations on Flutter API targeting store 2
    $flutterProductResp = $this->withToken($this->flutterToken)
        ->postJson("/api/flutter/v1/stores/{$this->store2->id}/products", [
            'name'          => 'Store 2 Exclusive',
            'barcode'       => '4782223334445',
            'type'          => Product::TYPE_PACKAGE,
            'initial_price' => 50000,
            'price'         => 80000,
            'stocks'        => [
                ['stock_id' => $this->stock2->id, 'quantity' => 25],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Store 2 Exclusive');

    // 3. Verify user's current_store_id in database is STILL store 1
    expect($this->user->fresh()->current_store_id)->toBe($this->store1->id);

    // 4. Flutter API does not set session cookies
    expect($flutterProductResp->headers->getCookies())->toBeEmpty();

    // 5. Existing mobile v1 API session continues to reflect web user state
    $v1Session = $this->actingAs($this->user, 'web')
        ->getJson('/mobile/api/v1/session')
        ->assertOk()
        ->json();

    expect($v1Session['store_id'])->toBe($this->store1->id);
});

it('ensures legacy v1 endpoints and flutter v1 endpoints coexist without regression', function (): void {
    // Create a product in store 1
    $product1 = Product::query()->create([
        'store_id'      => $this->store1->id,
        'name'          => 'Legacy Store 1 Perfume',
        'barcode'       => '4781112223334',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 40000,
        'price'         => 70000,
    ]);
    $product1->productStocks()->create(['stock_id' => $this->stock1->id, 'quantity' => 10]);

    // Legacy mobile v1 endpoint (session-based) returns product in store 1
    $this->actingAs($this->user, 'web')
        ->getJson('/mobile/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Legacy Store 1 Perfume');

    // Flutter API query for store 2 does not see store 1 product
    $this->withToken($this->flutterToken)
        ->getJson("/api/flutter/v1/stores/{$this->store2->id}/products")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Flutter API query for store 1 sees the product with canonical string format
    $this->withToken($this->flutterToken)
        ->getJson("/api/flutter/v1/stores/{$this->store1->id}/products")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $product1->id)
        ->assertJsonPath('data.0.price', '70000');
});
