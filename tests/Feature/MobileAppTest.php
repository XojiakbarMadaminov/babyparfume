<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use App\Services\SmsService;
use App\Models\DebtorTransaction;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches products using captured barcodes without navigating away', function (): void {
    $process = new Symfony\Component\Process\Process(['node', 'tests/Support/mobile-product-search.mjs'], base_path());
    $process->setTimeout(30)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->sms = $this->mock(SmsService::class);
    $this->sms->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->telegram = $this->mock(TelegramNotifier::class);
    $this->telegram->shouldReceive('sendMessage')->andReturn(true);
    $this->store = Store::query()->create(['name' => 'Asosiy', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['ViewAny:Debtor', 'View:Debtor', 'Create:Debtor', 'Update:Debtor', 'ViewAny:Product', 'View:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
});

function mobileDebtor(Store $store, int $amount = 500000, ?Client $client = null): Debtor
{
    $client ??= Client::factory()->create();

    return Debtor::withoutEvents(fn (): Debtor => Debtor::query()->create([
        'store_id' => $store->id, 'client_id' => $client->id, 'amount' => $amount, 'currency' => 'UZS', 'date' => now()->subDays(25),
    ]));
}

function mobileTransaction(Store $store, string $type = 'payment', int $amount = 100000): array
{
    return ['request_id' => (string) Str::uuid(), 'store_id' => $store->id, 'type' => $type, 'amount' => $amount, 'note' => 'Mobil'];
}

it('serves a separate mobile shell and deep links without livewire', function (): void {
    $this->withoutVite();
    $this->get('/mobile')->assertOk()->assertSee('mobile-app')->assertDontSee('livewire.js');
    $this->get('/mobile/debtors/12/debt')->assertOk()->assertSee('mobile-app');
    $this->getJson('/mobile/api/v1/unknown')->assertNotFound();
});

it('requires authentication for every mobile data endpoint', function (): void {
    auth()->logout();
    $this->getJson('/mobile/api/v1/session')->assertUnauthorized();
    $this->getJson('/mobile/api/v1/debtors')->assertUnauthorized();
    $this->postJson('/mobile/api/v1/debtors', [])->assertUnauthorized();
});

it('logs in with the existing user and rejects incorrect credentials', function (): void {
    $this->user->update(['password' => 'secret-password']);
    auth()->logout();
    $this->postJson('/mobile/api/v1/login', ['email' => $this->user->email, 'password' => 'incorrect'])->assertUnprocessable();
    $this->postJson('/mobile/api/v1/login', ['email' => $this->user->email, 'password' => 'secret-password'])->assertOk()->assertJsonPath('store_id', $this->store->id);
    $this->assertAuthenticatedAs($this->user);
});

it('requires explicit store membership and does not trust current_store_id alone', function (): void {
    $this->user->stores()->detach();
    $this->getJson('/mobile/api/v1/debtors')->assertForbidden();
    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('store_id', null);
});

it('returns the current csrf token after login session regeneration', function (): void {
    $this->user->update(['password' => 'secret-password']);
    auth()->logout();
    $response = $this->postJson('/mobile/api/v1/login', ['email' => $this->user->email, 'password' => 'secret-password']);
    $response->assertOk()->assertHeader('X-CSRF-TOKEN', session()->token());
});

it('rejects reads from a stale store tab', function (): void {
    $this->withHeader('X-Mobile-Store', (string) ($this->store->id + 1))->getJson('/mobile/api/v1/debtors')->assertConflict();
});

it('uses existing policy permissions', function (): void {
    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('permissions.debtors', true);
    $this->user->revokePermissionTo('ViewAny:Debtor');
    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('permissions.debtors', false);
    $this->getJson('/mobile/api/v1/debtors')->assertForbidden();
    $this->user->revokePermissionTo('ViewAny:Product');
    $this->getJson('/mobile/api/v1/products')->assertForbidden();
});

it('allows Shield roles to grant and revoke the debtor menu permission', function (): void {
    $this->user->syncPermissions([]);
    $role = Spatie\Permission\Models\Role::findOrCreate('mobile_debt_viewer', 'web');
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:Debtor', 'web'));
    $this->user->assignRole($role);

    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('permissions.debtors', true);
    $this->getJson('/mobile/api/v1/debtors')->assertOk();

    $role->revokePermissionTo('ViewAny:Debtor');
    $this->user->unsetRelation('roles')->unsetRelation('permissions');

    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('permissions.debtors', false);
    $this->getJson('/mobile/api/v1/debtors')->assertForbidden();
});

it('exposes mobile custom permissions in Shield role forms', function (): void {
    expect(config('filament-shield.shield_resource.tabs.custom_permissions'))->toBeTrue()
        ->and(app(BezhanSalleh\FilamentShield\FilamentShield::class)->getCustomPermissions())
        ->toHaveKey('view_mobile_sales_cost_price', 'Mobil Sotuvda Kelgan Narxni Ko‘rish')
        ->toHaveKey('view_mobile_sales_statistics', 'Mobil Sotuv Statistikasini Ko‘rish');
});

it('saves mobile custom permissions through the Shield role editor', function (): void {
    $this->withoutVite();
    Filament\Facades\Filament::setCurrentPanel('admin');
    foreach (['ViewAny:Role', 'View:Role', 'Update:Role'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $role = Spatie\Permission\Models\Role::findOrCreate('mobile_cashier', 'web');

    $cacheWasDisabled = Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache::$disableBackButtonCache;

    try {
        Livewire\Livewire::test(BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole::class, ['record' => $role->id])
            ->fillForm(['custom_permissions_tab' => ['view_mobile_sales_cost_price', 'view_mobile_sales_statistics']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($role->refresh()->hasPermissionTo('view_mobile_sales_cost_price'))->toBeTrue()
            ->and($role->hasPermissionTo('view_mobile_sales_statistics'))->toBeTrue()
            ->and($role->hasPermissionTo(Permission::findOrCreate('View:SalesStatsOverview', 'web')))->toBeFalse();
    } finally {
        Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache::$disableBackButtonCache = $cacheWasDisabled;
    }
});

it('isolates debtor lists details and transactions to the current store', function (): void {
    $own        = mobileDebtor($this->store);
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Samarqand', 'phone' => '+998901234568']);
    $foreign    = mobileDebtor($otherStore);
    $this->getJson('/mobile/api/v1/debtors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('summary.0.amount', 500000);
    $this->getJson('/mobile/api/v1/debtors/' . $foreign->id)->assertNotFound();
    $this->getJson('/mobile/api/v1/debtors/' . $foreign->id . '/transactions')->assertNotFound();
    $this->postJson('/mobile/api/v1/debtors/' . $foreign->id . '/transactions', mobileTransaction($this->store))->assertNotFound();
});

it('searches filters and paginates debtors', function (): void {
    $client = Client::factory()->create(['full_name' => 'Aziza Karimova', 'phone' => '+998901111111']);
    mobileDebtor($this->store, 100000, $client);
    mobileDebtor($this->store, 0);
    $this->getJson('/mobile/api/v1/debtors?q=Aziza&filter=overdue')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/mobile/api/v1/debtors?filter=active')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/mobile/api/v1/debtors?filter=all')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/mobile/api/v1/debtors?page=-1')->assertUnprocessable();
});

it('returns closed debtors and store scoped filter counts independently of search and pagination', function (): void {
    $overdue = mobileDebtor($this->store);
    mobileDebtor($this->store)->updateQuietly(['date' => now()->toDateString()]);
    $closedClient = Client::factory()->create(['full_name' => 'Yopilgan Mijoz']);
    $closed       = mobileDebtor($this->store, 0, $closedClient);
    foreach (range(1, 20) as $index) {
        mobileDebtor($this->store, 0);
    }
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Samarqand', 'phone' => '+998901234568']);
    mobileDebtor($otherStore);
    mobileDebtor($otherStore, 0);

    $this->getJson('/mobile/api/v1/debtors?filter=closed')
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)
        ->assertJsonPath('counts', ['active' => 2, 'closed' => 21, 'overdue' => 1]);
    $this->getJson('/mobile/api/v1/debtors?filter=closed&q=Yopilgan')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed->id)
        ->assertJsonPath('counts', ['active' => 2, 'closed' => 21, 'overdue' => 1]);
    $this->getJson('/mobile/api/v1/debtors?filter=overdue')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $overdue->id);
});

it('accepts a payment without recording a payment method', function (): void {
    $debtor  = mobileDebtor($this->store);
    $payment = mobileTransaction($this->store);
    $this->postJson('/mobile/api/v1/debtors/' . $debtor->id . '/transactions', $payment)
        ->assertOk()->assertJsonPath('data.amount', 400000);
    expect($debtor->transactions()->sole()->note)->toBe('Mobil');
});

it('preserves the entered phone and creates an initial transaction exactly once', function (): void {
    $data     = ['request_id' => (string) Str::uuid(), 'store_id' => $this->store->id, 'full_name' => 'Yangi Mijoz', 'phone' => '90 123 45 69', 'amount' => 300000, 'date' => now()->toDateString()];
    $response = $this->postJson('/mobile/api/v1/debtors', $data)->assertSuccessful()->assertJsonPath('data.amount', 300000)->assertJsonPath('data.phone', '901234569');
    $this->postJson('/mobile/api/v1/debtors', $data)->assertSuccessful()->assertJsonPath('data.id', $response->json('data.id'));
    expect(Debtor::query()->count())->toBe(1);
    expect(DebtorTransaction::query()->count())->toBe(1);
    $this->sms->shouldHaveReceived('sendSms')->once();
    $this->telegram->shouldHaveReceived('sendMessage')->once();
    Queue::assertNothingPushed();
});

it('rejects duplicate active clients and reuses legacy phone formats safely', function (): void {
    $client = Client::factory()->create(['phone' => '998901234569']);
    mobileDebtor($this->store, 100000, $client);
    $data = ['request_id' => (string) Str::uuid(), 'store_id' => $this->store->id, 'full_name' => 'Yangi', 'phone' => '+998901234569', 'amount' => 300000, 'date' => now()->toDateString()];
    $this->postJson('/mobile/api/v1/debtors', $data)->assertUnprocessable()->assertJsonValidationErrors('phone');
    expect(Client::query()->count())->toBe(1);
});

it('restores an existing soft deleted client without changing its identity', function (): void {
    $client = Client::factory()->create(['phone' => '+998901234569']);
    $client->delete();
    $data = ['request_id' => (string) Str::uuid(), 'store_id' => $this->store->id, 'full_name' => 'Boshqa ism', 'phone' => '+998901234569', 'amount' => 100, 'date' => now()->toDateString()];
    $this->postJson('/mobile/api/v1/debtors', $data)->assertSuccessful();
    expect($client->fresh()->deleted_at)->toBeNull();
    expect(Client::withTrashed()->count())->toBe(1);
});

it('adds debt and payment atomically with idempotent retries', function (): void {
    $debtor = mobileDebtor($this->store);
    $debt   = mobileTransaction($this->store, 'debt', 200000);
    $url    = '/mobile/api/v1/debtors/' . $debtor->id . '/transactions';
    $this->postJson($url, $debt)->assertOk()->assertJsonPath('data.amount', 700000);
    $this->postJson($url, $debt)->assertOk()->assertJsonPath('data.amount', 700000);
    $payment = mobileTransaction($this->store, 'payment', 100000) + ['payment_method' => 'card'];
    $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.amount', 600000);
    $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.amount', 600000);
    expect($debtor->fresh()->amount)->toBe(600000);
    expect($debtor->transactions()->count())->toBe(2);
    $this->sms->shouldHaveReceived('sendSms')->twice();
    $this->telegram->shouldHaveReceived('sendMessage')->twice();
    Queue::assertNothingPushed();
});

it('rejects reusing a request id for a different amount or actor', function (): void {
    $debtor = mobileDebtor($this->store);
    $data   = mobileTransaction($this->store);
    $url    = '/mobile/api/v1/debtors/' . $debtor->id . '/transactions';
    $this->postJson($url, $data)->assertOk();
    $this->postJson($url, array_replace($data, ['amount' => 200000]))->assertConflict();
    $otherUser = User::factory()->create(['current_store_id' => $this->store->id]);
    $otherUser->stores()->attach($this->store);
    $otherUser->givePermissionTo('Update:Debtor');
    $this->actingAs($otherUser)->postJson($url, $data)->assertConflict();
    expect($debtor->fresh()->amount)->toBe(400000);
});

it('requires debtor update permission for financial changes', function (): void {
    $debtor = mobileDebtor($this->store);
    $this->user->revokePermissionTo('Update:Debtor');
    $this->postJson('/mobile/api/v1/debtors/' . $debtor->id . '/transactions', mobileTransaction($this->store))->assertForbidden();
    expect($debtor->fresh()->amount)->toBe(500000);
    expect($debtor->transactions()->count())->toBe(0);
});

it('does not overpay a debt or create any transaction on failure', function (): void {
    $debtor = mobileDebtor($this->store);
    $this->postJson('/mobile/api/v1/debtors/' . $debtor->id . '/transactions', mobileTransaction($this->store, 'payment', 500001))->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect($debtor->fresh()->amount)->toBe(500000);
    expect($debtor->transactions()->count())->toBe(0);
    $this->sms->shouldNotHaveReceived('sendSms');
    $this->telegram->shouldNotHaveReceived('sendMessage');
    Queue::assertNothingPushed();
});

it('uses the web observer debt closure notification once for a repeated mobile payment', function (): void {
    $debtor  = mobileDebtor($this->store, 100000);
    $payment = mobileTransaction($this->store, 'payment', 100000);
    $url     = '/mobile/api/v1/debtors/' . $debtor->id . '/transactions';
    $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.amount', 0);
    $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.amount', 0);

    $this->sms->shouldHaveReceived('sendSms')->withArgs(fn (string $phone, string $message): bool => $phone === $debtor->client->phone && str_contains($message, "qarzdorligingiz to'liq yopildi."))->once();
    $this->telegram->shouldHaveReceived('sendMessage')->once();
    Queue::assertNothingPushed();
});

it('rejects nonpositive fractional and excessive transaction amounts', function (mixed $amount): void {
    $debtor = mobileDebtor($this->store);
    $data   = array_replace(mobileTransaction($this->store), ['amount' => $amount]);
    $this->postJson('/mobile/api/v1/debtors/' . $debtor->id . '/transactions', $data)->assertUnprocessable();
    expect($debtor->transactions()->count())->toBe(0);
})->with([0, -100, 0.5, 'invalid', 1000000000000]);

it('rejects writes from a page opened for another store', function (): void {
    $debtor = mobileDebtor($this->store);
    $data   = array_replace(mobileTransaction($this->store), ['store_id' => 999]);
    $this->postJson('/mobile/api/v1/debtors/' . $debtor->id . '/transactions', $data)->assertConflict();
    expect($debtor->fresh()->amount)->toBe(500000);
});

it('allows a full repayment and subsequently adding new debt', function (): void {
    $debtor = mobileDebtor($this->store);
    $url    = '/mobile/api/v1/debtors/' . $debtor->id . '/transactions';
    $this->postJson($url, mobileTransaction($this->store, 'payment', 500000))->assertOk()->assertJsonPath('data.amount', 0);
    $this->postJson($url, mobileTransaction($this->store, 'debt', 100000))->assertOk()->assertJsonPath('data.amount', 100000);
});

it('only allows changing to an assigned store', function (): void {
    $other = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->postJson('/mobile/api/v1/store', ['store_id' => $other->id])->assertNotFound();
    $this->user->stores()->attach($other);
    $this->postJson('/mobile/api/v1/store', ['store_id' => $other->id])->assertOk()->assertJsonPath('store_id', $other->id);
});

it('returns products with only current store stock quantities and hides cost price', function (): void {
    $stock        = Stock::query()->create(['name' => 'Asosiy ombor']);
    $foreignStock = Stock::query()->create(['name' => 'Begona ombor']);
    $this->store->stocks()->attach($stock);
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => '6291108730515']);
    ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $stock->id, 'quantity' => 24]);
    ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $foreignStock->id, 'quantity' => 999]);
    $response = $this->getJson('/mobile/api/v1/products/lookup?barcode=6291108730515')->assertOk()->assertJsonPath('data.quantity', 24)->assertJsonMissingPath('data.initial_price');
    expect($response->json('data.id'))->toBe($product->id);
    $this->getJson('/mobile/api/v1/products?q=6291108730515')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/mobile/api/v1/products/lookup?barcode=missing')->assertNotFound();
});

it('does not expose a product present only in another store', function (): void {
    $stock   = Stock::query()->create(['name' => 'Begona ombor']);
    $product = Product::factory()->create();
    ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $stock->id, 'quantity' => 1]);
    $this->getJson('/mobile/api/v1/products/' . $product->id)->assertNotFound();
    $this->getJson('/mobile/api/v1/products/lookup?barcode=' . $product->barcode)->assertNotFound();
});

it('counts unified size stock without double counting legacy rows', function (): void {
    $stock = Stock::query()->create(['name' => 'Asosiy ombor']);
    $this->store->stocks()->attach($stock);
    $product = Product::factory()->create(['store_id' => $this->store->id, 'type' => 'size']);
    $size    = ProductSize::query()->create(['product_id' => $product->id, 'size' => '100 ml']);
    $size->stocks()->create(['stock_id' => $stock->id, 'quantity' => 99]);
    $size->productStocks()->create(['stock_id' => $stock->id, 'quantity' => 7]);
    $this->getJson('/mobile/api/v1/products/' . $product->id)->assertOk()->assertJsonPath('data.quantity', 7);
});
