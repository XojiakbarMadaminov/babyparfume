<?php

use App\Models\User;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('protected mobile endpoints', [
    'session'            => ['GET', '/session'],
    'switch store'       => ['POST', '/store'],
    'logout'             => ['POST', '/logout'],
    'statistics'         => ['GET', '/sales/statistics'],
    'products'           => ['GET', '/products'],
    'create product'     => ['POST', '/products'],
    'product options'    => ['GET', '/products/create-options'],
    'generate barcode'   => ['GET', '/products/barcode'],
    'lookup barcode'     => ['GET', '/products/lookup'],
    'product detail'     => ['GET', '/products/1'],
    'update product'     => ['PATCH', '/products/1'],
    'upload product'     => ['POST', '/products/1'],
    'delete product'     => ['DELETE', '/products/1'],
    'debtors'            => ['GET', '/debtors'],
    'create debtor'      => ['POST', '/debtors'],
    'debtor detail'      => ['GET', '/debtors/1'],
    'update debtor'      => ['PATCH', '/debtors/1'],
    'upload debtor'      => ['POST', '/debtors/1'],
    'debtor history'     => ['GET', '/debtors/1/transactions'],
    'debtor transaction' => ['POST', '/debtors/1/transactions'],
]);

dataset('store scoped mobile reads', [
    'statistics'       => ['/sales/statistics'],
    'products'         => ['/products'],
    'product options'  => ['/products/create-options'],
    'generate barcode' => ['/products/barcode'],
    'lookup barcode'   => ['/products/lookup'],
    'debtors'          => ['/debtors'],
]);

it('requires a web session on every protected mobile endpoint', function (string $method, string $path): void {
    $this->json($method, '/mobile/api/v1' . $path)->assertUnauthorized();
})->with('protected mobile endpoints');

it('rejects store scoped reads after membership is revoked', function (string $path): void {
    $store = Store::query()->create(['name' => 'Mobil shartnoma', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);
    $this->actingAs($user);
    $user->stores()->detach($store);

    $this->getJson('/mobile/api/v1' . $path)->assertForbidden();

    expect($user->fresh()->current_store_id)->toBe($store->id);
})->with('store scoped mobile reads');

it('rejects stale store headers before serving store data', function (string $path): void {
    $store      = Store::query()->create(['name' => 'Mobil shartnoma', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $otherStore = Store::query()->create(['name' => 'Boshqa do‘kon', 'address' => 'Toshkent', 'phone' => '+998909876543']);
    $user       = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach([$store->id, $otherStore->id]);

    $this->actingAs($user)
        ->withHeader('X-Mobile-Store', (string) $otherStore->id)
        ->getJson('/mobile/api/v1' . $path)
        ->assertConflict();

    expect($user->fresh()->current_store_id)->toBe($store->id);
})->with('store scoped mobile reads');

it('keeps session reads separate from store selection and desktop carts', function (): void {
    $store = Store::query()->create(['name' => 'Mobil shartnoma', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);
    $desktopCarts = [1 => ['desktop-marker']];
    $mobileCarts  = ['pos_carts' => [1 => ['mobile-marker']]];

    $response = $this->actingAs($user)
        ->withSession(['pos_carts' => $desktopCarts, 'mobile_sales' => $mobileCarts])
        ->getJson('/mobile/api/v1/session')
        ->assertOk()
        ->assertJsonPath('store_id', $store->id)
        ->assertJsonPath('stores.0.id', $store->id)
        ->assertJsonPath('permissions.products', false)
        ->assertJsonPath('permissions.debtors', false)
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token')
        ->assertSessionHas('pos_carts', $desktopCarts)
        ->assertSessionHas('mobile_sales', $mobileCarts);

    $response->assertHeader('X-CSRF-TOKEN', session()->token());
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($user->fresh()->current_store_id)->toBe($store->id);
});

it('does not change the selected store when switching to an unassigned store', function (): void {
    $store      = Store::query()->create(['name' => 'Mobil shartnoma', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $otherStore = Store::query()->create(['name' => 'Begona do‘kon', 'address' => 'Toshkent', 'phone' => '+998909876543']);
    $user       = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);

    $this->actingAs($user)
        ->postJson('/mobile/api/v1/store', ['store_id' => $otherStore->id])
        ->assertNotFound();

    expect($user->fresh()->current_store_id)->toBe($store->id);
});

it('invalidates the shared web session and both carts on mobile logout', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession([
        'pos_carts'    => [1 => ['desktop-marker']],
        'mobile_sales' => ['pos_carts' => [1 => ['mobile-marker']]],
    ]);
    $oldToken = session()->token();

    $this->postJson('/mobile/api/v1/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Hisobdan chiqildi.')
        ->assertSessionMissing('pos_carts')
        ->assertSessionMissing('mobile_sales');

    $this->assertGuest('web');
    expect(session()->token())->not->toBe($oldToken);
    $this->getJson('/mobile/api/v1/session')->assertUnauthorized();
});
