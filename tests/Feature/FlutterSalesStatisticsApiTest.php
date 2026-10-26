<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('flutter.enabled', true);
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Do‘kon B', 'address' => 'Samarqand', 'phone' => '+998902222222']);

    $this->stockA = Stock::query()->create(['name' => 'Ombor A', 'is_active' => true]);
    $this->stockA->stores()->attach($this->storeA);

    $this->productA = Product::factory()->create(['store_id' => $this->storeA->id, 'initial_price' => 100]);

    $this->user = User::factory()->create([
        'email'            => 'stats_user@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach([$this->storeA->id, $this->storeB->id]);

    $role        = Role::findOrCreate('admin', 'web');
    $permissions = ['View:Pos', 'view_mobile_sales_statistics'];
    foreach ($permissions as $p) {
        $perm = Permission::findOrCreate($p, 'web');
        $role->givePermissionTo($perm);
        $this->user->givePermissionTo($perm);
    }

    $loginResponse = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'stats_user@example.com',
        'password'    => 'password',
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'iPhone 15',
        'platform'    => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('calculates sales statistics scoped strictly to the requested store', function (): void {
    // Sale in Store A
    $saleA = Sale::query()->create([
        'store_id'         => $this->storeA->id,
        'cart_id'          => 1,
        'total_amount'     => 10000,
        'paid_amount'      => 10000,
        'remaining_amount' => 0,
        'payment_type'     => 'cash',
        'status'           => Sale::STATUS_COMPLETED,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $saleA->items()->create([
        'product_id' => $this->productA->id,
        'stock_id'   => $this->stockA->id,
        'quantity'   => 2,
        'price'      => 5000,
        'unit_cost'  => 1000,
        'total'      => 10000,
    ]);

    // Sale in Store B
    Sale::query()->create([
        'store_id'         => $this->storeB->id,
        'cart_id'          => 1,
        'total_amount'     => 50000,
        'paid_amount'      => 50000,
        'remaining_amount' => 0,
        'payment_type'     => 'cash',
        'status'           => Sale::STATUS_COMPLETED,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $url = "/api/flutter/v1/stores/{$this->storeA->id}/statistics?period=today&start=2026-10-21&end=2026-10-21";

    $response = $this->withToken($this->token)
        ->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.store_id', (string) $this->storeA->id)
        ->assertJsonPath('data.sales_amount', '10000')
        ->assertJsonPath('data.sales_count', 1)
        ->assertJsonPath('data.payments.0.percent', 100)
        ->assertJsonPath('data.top_products.0.id', (string) $this->productA->id)
        ->assertJsonPath('data.top_products.0.quantity', 2)
        ->assertJsonPath('data.currency', 'UZS');

    // Store B sales must not leak into Store A statistics
    expect($response->json('data.sales_amount'))->toBe('10000');
});

it('denies access to sales statistics when permissions are missing', function (): void {
    $limitedUser = User::factory()->create(['email' => 'no_stats@example.com']);
    $limitedUser->stores()->attach($this->storeA);

    $loginResp = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'no_stats@example.com',
        'password'    => 'password',
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Android',
        'platform'    => 'android',
    ])->assertCreated();

    $token = $loginResp->json('data.token');

    $this->withToken($token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/statistics?period=today&start=2026-10-21&end=2026-10-21")
        ->assertForbidden();
});
