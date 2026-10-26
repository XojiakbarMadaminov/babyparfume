<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use App\Services\SaleDebtService;
use App\Filament\Pages\SalesHistoryPage;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->mock(App\Services\TelegramSaleNotifier::class)->shouldReceive('notify')->andReturnNull();
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    $this->store      = Store::query()->create(['name' => 'Savdo', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->user       = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach([$this->store->id, $this->otherStore->id]);
    $this->user->givePermissionTo(Permission::findOrCreate('View:Pos', 'web'));
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');
    $this->stock = Stock::query()->create(['name' => 'Ombor', 'is_main' => true, 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->product = Product::factory()->create([
        'store_id' => $this->store->id, 'type' => 'package', 'initial_price' => 100000, 'price' => 150000,
    ]);
    $this->product->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 10]);
    $this->client = Client::factory()->create();
});

it('adds checkout debt to the active debtor instead of reopening an older closed row', function (string $component, string $method): void {
    $closed = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 0, 'currency' => 'UZS',
    ]);
    $active = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 50000, 'currency' => 'UZS',
    ]);
    $foreign = Debtor::query()->create([
        'store_id' => $this->otherStore->id, 'client_id' => $this->client->id, 'amount' => 90000, 'currency' => 'UZS',
    ]);
    $page = Livewire::test($component)->call('add', $this->product->id)
        ->call('selectClient', $this->client->id)->call('selectPaymentType', $method);
    if ($method === 'partial') {
        $page->set('partialPaymentAmount', 50000);
    }
    $page->call('checkout')->assertSet('showReceipt', true)->assertSet('cart', []);

    $remaining = $method === 'debt' ? 150000 : 100000;
    $sale      = Sale::query()->sole();
    expect(Debtor::withoutGlobalScopes()->count())->toBe(3)
        ->and((int) $active->fresh()->amount)->toBe(50000 + $remaining)
        ->and((int) $closed->fresh()->amount)->toBe(0)
        ->and((int) $foreign->fresh()->amount)->toBe(90000)
        ->and($sale->transactions()->sole()->debtor_id)->toBe($active->id)
        ->and((int) $sale->transactions()->sole()->amount)->toBe($remaining);
})->with(['web' => [Pos::class], 'mobile' => [Sales::class]])->with(['debt', 'partial']);

it('reuses a closed debtor and creates a debtor only when none exists at checkout', function (string $component, bool $existing): void {
    $closed = $existing ? Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 0, 'currency' => 'UZS',
    ]) : null;

    Livewire::test($component)->call('add', $this->product->id)
        ->call('selectClient', $this->client->id)->call('selectPaymentType', 'debt')
        ->call('checkout')->assertSet('showReceipt', true);

    $debtor = Debtor::query()->sole();
    expect((int) $debtor->amount)->toBe(150000)
        ->and($debtor->client_id)->toBe($this->client->id)
        ->and($debtor->transactions()->count())->toBe(1);
    if ($closed) {
        expect($debtor->id)->toBe($closed->id);
    }
})->with(['web' => [Pos::class], 'mobile' => [Sales::class]])->with(['existing' => [true], 'new' => [false]]);

it('uses the active debtor when a pending order is finalized on credit', function (string $method): void {
    $this->user->givePermissionTo(Permission::findOrCreate('View:SalesHistoryPage', 'web'));
    $closed = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 0, 'currency' => 'UZS',
    ]);
    $active = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 50000, 'currency' => 'UZS',
    ]);
    $sale = Sale::query()->create([
        'store_id'     => $this->store->id, 'client_id' => $this->client->id, 'cart_id' => 1,
        'total_amount' => 150000, 'remaining_amount' => 150000, 'paid_amount' => 0,
        'payment_type' => 'preorder', 'status' => Sale::STATUS_PENDING,
    ]);

    Livewire::test(SalesHistoryPage::class)
        ->callAction(TestAction::make('finalize_pending')->table($sale), data: [
            'payment_type' => $method, 'partial_amount' => 50000, 'note' => 'Buyurtma qarzi',
        ])->assertHasNoActionErrors();

    $remaining = $method === 'debt' ? 150000 : 100000;
    expect($sale->fresh()->status)->toBe(Sale::STATUS_COMPLETED)
        ->and((int) $active->fresh()->amount)->toBe(50000 + $remaining)
        ->and((int) $closed->fresh()->amount)->toBe(0)
        ->and(Debtor::query()->count())->toBe(2)
        ->and($sale->transactions()->sole()->debtor_id)->toBe($active->id);
})->with(['debt', 'partial']);

it('keeps other currencies separate and reuses legacy uzs accounts regardless of case', function (): void {
    $foreignCurrency = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 100, 'currency' => 'USD',
    ]);
    $uzs = Debtor::query()->create([
        'store_id' => $this->store->id, 'client_id' => $this->client->id, 'amount' => 50000, 'currency' => 'uzs',
    ]);
    $sale = Sale::query()->create([
        'store_id'     => $this->store->id, 'client_id' => $this->client->id, 'cart_id' => 1,
        'total_amount' => 150000, 'remaining_amount' => 150000, 'payment_type' => 'debt',
    ]);

    $debtor = app(SaleDebtService::class)->record($sale, 150000);

    expect($debtor->id)->toBe($uzs->id)
        ->and((int) $uzs->fresh()->amount)->toBe(200000)
        ->and((int) $foreignCurrency->fresh()->amount)->toBe(100)
        ->and(Debtor::query()->count())->toBe(2);
});
