<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    it('renders the real pos component and preserves server cart actions')->skip('The test database requires the pdo_sqlite PHP extension.');

    return;
}

uses(RefreshDatabase::class);

it('preserves mobile checkout payment totals and starts a new sale', function (string $method, float $paid): void {
    $this->withoutVite();
    $this->mock(App\Services\TelegramSaleNotifier::class)->shouldReceive('notify')->andReturnNull();
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    $store = Store::query()->create(['name' => 'Mobil test', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);
    $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('View:Pos', 'web'));
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    $stock = Stock::query()->create(['name' => 'Asosiy', 'is_main' => true, 'is_active' => true]);
    $stock->stores()->attach($store);
    $product   = Product::factory()->create(['store_id' => $store->id, 'type' => 'package', 'initial_price' => 100000, 'price' => 150000]);
    $inventory = App\Models\ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $stock->id, 'quantity' => 10]);
    $client    = App\Models\Client::factory()->create();
    $page      = Livewire::test(Sales::class)
        ->call('add', $product->id)
        ->call('selectClient', $client->id)
        ->call('selectPaymentType', $method);

    if ($method === 'partial') {
        $page->set('partialPaymentAmount', $paid);
    }
    if ($method === 'mixed') {
        $page->set('mixedPayment.card', 100000);
    }

    $page->call('checkout')->assertSet('showReceipt', true)
        ->assertSee('Savdo muvaffaqiyatli')->assertSet('cart', [])
        ->assertSet('receiptData.totals.amount', 150000);
    expect($page->get('receiptData.items'))->not->toBeEmpty();
    foreach ($page->get('receiptData.items') as $item) {
        expect($item)->not->toHaveKeys(['initial_price', 'yuan_price']);
    }
    $page->call('closeReceipt')->assertSet('showReceipt', false)->assertSet('totals.qty', 0);

    $sale = App\Models\Sale::query()->sole();
    expect($sale->payment_type)->toBe($method)
        ->and($sale->total_amount)->toBe(150000.0)
        ->and($sale->paid_amount)->toBe($paid)
        ->and($sale->remaining_amount)->toBe(150000.0 - $paid)
        ->and($sale->items()->sole()->unit_cost)->toBe('100000.00')
        ->and($inventory->fresh()->quantity)->toEqual(9);
    $page->call('checkout');
    $this->assertDatabaseCount('sales', 1);
})->with([
    'cash'     => ['cash', 150000.0],
    'card'     => ['card', 150000.0],
    'transfer' => ['transfer', 150000.0],
    'debt'     => ['debt', 0.0],
    'partial'  => ['partial', 50000.0],
    'mixed'    => ['mixed', 150000.0],
    'preorder' => ['preorder', 0.0],
]);

it('renders the real pos component and preserves server cart actions', function (): void {
    $this->withoutVite();
    $store = Store::query()->create(['name' => 'Million', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);
    $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('View:Pos', 'web'));
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    $stock = Stock::query()->create(['name' => 'Asosiy', 'is_main' => true, 'is_active' => true]);
    $stock->stores()->attach($store);
    $product = Product::factory()->create(['store_id' => $store->id, 'name' => 'Mobil test atiri', 'type' => 'package', 'initial_price' => 100000, 'price' => 150000]);

    Livewire::test(Sales::class)
        ->assertStatus(200)
        ->assertSee('Mobil savdo')
        ->assertSee('Faol savatlar')
        ->call('add', $product->id)
        ->assertSet('totals.qty', 1)
        ->assertSet('totals.amount', 150000)
        ->call('updatePackageQty', $product->id, 2)
        ->assertSet('totals.qty', 2)
        ->assertSet('totals.amount', 300000)
        ->call('updatePrice', $product->id, 120000)
        ->assertSet('totals.amount', 240000)
        ->call('updatePrice', $product->id, 100)
        ->assertSet('totals.amount', 240000)
        ->call('remove', $product->id)
        ->assertSet('totals.qty', 0)
        ->assertSet('cart', []);

    $desktopCart = [1 => ['desktop-only' => ['name' => 'Desktop savati']]];
    $response    = $this->withSession(['pos_carts' => $desktopCart])
        ->get('/mobile/sales-workspace')->assertOk()->assertSee('Sotuv');
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    expect(json_decode($snapshot, true)['memo']['name'])->toBe('mobile.sales');

    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot,
        'updates'  => [],
        'calls'    => [['path' => '', 'method' => 'add', 'params' => [$product->id]]],
    ]]], ['X-Livewire' => 'true'])->assertOk();

    expect(session('pos_carts'))->toBe($desktopCart)
        ->and(session('mobile_sales.' . $user->id . '.' . $store->id . '.pos_carts.1.' . $product->id . '.qty'))->toBe(1);
});

it('checks cost price access on the server for product cards and cart items', function (string $role, bool $canUpdate, bool $visible): void {
    $this->withoutVite();
    $store = Store::query()->create(['name' => 'Million', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store, ['role' => $role]);
    $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('View:Pos', 'web'));
    if ($canUpdate) {
        $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('Update:Product', 'web'));
    }
    if ($visible) {
        $permission = Spatie\Permission\Models\Permission::findOrCreate('view_mobile_sales_cost_price', 'web');
        $shieldRole = Spatie\Permission\Models\Role::findOrCreate('mobile_cost_viewer', 'web');
        $shieldRole->givePermissionTo($permission);
        $user->assignRole($shieldRole);
    }
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    $stock = Stock::query()->create(['name' => 'Asosiy', 'is_main' => true, 'is_active' => true]);
    $stock->stores()->attach($store);
    $product = Product::factory()->create(['store_id' => $store->id, 'type' => 'package', 'initial_price' => 87654, 'price' => 123456]);
    $page    = Livewire::test(Sales::class)->call('add', $product->id);
    if ($visible) {
        $page->assertSee('Kelgan narxi: 87 654 so‘m')->assertSee('Kelgan narxi: 87 654 so‘m / dona');
    } else {
        $page->assertDontSee('Kelgan narxi')->assertDontSee('87 654');
        expect($page->get('cart')[$product->id])->not->toHaveKeys(['initial_price', 'yuan_price']);
    }
    expect(app(App\Services\CartService::class)->all(1)[$product->id]['initial_price'])->toEqual(87654);

    if ($visible) {
        $shieldRole->revokePermissionTo($permission);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $page->call('$refresh')->assertDontSee('Kelgan narxi');
        expect($page->get('cart')[$product->id])->not->toHaveKeys(['initial_price', 'yuan_price']);
    }
})->with([
    'cashier without permission'             => ['cashier', false, false],
    'cashier with product update permission' => ['cashier', true, false],
    'store admin without permission'         => ['admin', false, false],
    'store owner without permission'         => ['owner', false, false],
    'cashier with cost permission'           => ['cashier', false, true],
    'store admin with cost permission'       => ['admin', false, true],
    'store owner with cost permission'       => ['owner', false, true],
]);
