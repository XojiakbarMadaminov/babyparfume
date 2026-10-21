<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Livewire\Livewire;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\SmsService;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use App\Services\TelegramNotifier;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Debtors\Pages\ListDebtors;
use App\Filament\Resources\Products\Pages\ListProducts;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->mock(SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);

    $this->store = Store::query()->create(['name' => 'Qidiruv', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    $this->user->assignRole(Role::findOrCreate('super_admin', 'web'));

    foreach (['ViewAny:Product', 'View:Product', 'ViewAny:Client', 'ViewAny:Debtor', 'View:Debtor', 'View:Pos'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $stock = Stock::query()->create(['name' => 'Ombor', 'is_active' => true, 'is_main' => true]);
    $stock->stores()->attach($this->store);
    $this->product = Product::factory()->create(['name' => 'MiXeD Atir', 'store_id' => $this->store->id]);
    ProductStock::query()->create(['product_id' => $this->product->id, 'stock_id' => $stock->id, 'quantity' => 15]);
    $this->client = Client::query()->create(['full_name' => 'MiXeD Mijoz', 'phone' => '+998901112233']);
    $this->debtor = Debtor::query()->create([
        'store_id'  => $this->store->id,
        'client_id' => $this->client->id,
        'amount'    => 100000,
        'currency'  => 'UZS',
        'date'      => today()->toDateString(),
    ]);
});

it('searches mobile debtors regardless of name case', function (string $search): void {
    $this->getJson(route('mobile.api.debtors', ['q' => $search]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->debtor->id);
})->with(['mixed', 'MIXED', 'mIxEd', '112233']);

it('does not return unrelated mobile debtors', function (): void {
    $this->getJson(route('mobile.api.debtors', ['q' => 'Boshqa']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('searches mobile products regardless of name case', function (string $search): void {
    $this->getJson(route('mobile.api.products', ['q' => $search]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->product->id);
})->with(['mixed', 'MIXED', 'mIxEd']);

it('searches admin tables regardless of name case', function (string $search): void {
    Livewire::test(ListDebtors::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$this->debtor]);

    Livewire::test(ListProducts::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$this->product]);

    Livewire::test(ListClients::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$this->client]);
})->with(['mixed', 'MIXED', 'mIxEd']);

it('searches sales products and clients regardless of name case', function (string $search): void {
    Livewire::test(Sales::class)
        ->set('search', $search)
        ->assertSee($this->product->name)
        ->set('searchClient', $search)
        ->assertSet('clients', fn ($clients): bool => $clients->contains('id', $this->client->id));
})->with(['mixed', 'MIXED', 'mIxEd']);
