<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Carbon;
use App\Models\CashTransaction;
use App\Models\InventoryAdjustment;
use Spatie\Permission\Models\Permission;
use App\Filament\Widgets\SalesStatsOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-17 12:00:00'));
    $this->store = Store::query()->create(['name' => 'Million', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['View:Pos', 'View:SalesStatsOverview', 'View:TopSellingProductsChart'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->product = Product::factory()->create(['store_id' => $this->store->id, 'initial_price' => 100]);
    $this->stock   = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->url  = '/mobile/api/v1/sales/statistics?period=today&start=2026-10-17&end=2026-10-17';
    $this->sale = function (array $attributes = []): Sale {
        $sale = Sale::withoutEvents(fn (): Sale => Sale::query()->create(array_replace([
            'store_id'    => $this->store->id, 'cart_id' => 1, 'total_amount' => 1000,
            'paid_amount' => 1000, 'remaining_amount' => 0, 'payment_type' => 'cash',
            'status'      => Sale::STATUS_COMPLETED, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes)));
        $sale->items()->create([
            'product_id' => $this->product->id, 'stock_id' => $this->stock->id,
            'quantity'   => 2, 'price' => $sale->total_amount / 2, 'total' => $sale->total_amount,
        ]);

        return $sale;
    };
});

it('returns completed sales and saved mixed and partial payment amounts without writes', function (): void {
    foreach ([
        ['total_amount' => 1000],
        ['total_amount' => 2000, 'payment_type' => 'card'],
        ['total_amount' => 3000, 'payment_type' => 'transfer'],
        ['total_amount' => 4000, 'payment_type' => 'debt', 'paid_amount' => 0, 'remaining_amount' => 4000],
        ['total_amount' => 5000, 'payment_type' => 'partial', 'paid_amount' => 2000, 'remaining_amount' => 3000],
        ['total_amount' => 6000, 'payment_type' => 'mixed', 'mixed_cash_amount' => 2500, 'mixed_card_amount' => 3500],
        ['total_amount' => 90000, 'status' => Sale::STATUS_PENDING],
        ['total_amount' => 80000, 'status' => Sale::STATUS_REJECTED],
    ] as $attributes) {
        ($this->sale)($attributes);
    }
    ($this->sale)(['total_amount' => 10000, 'created_at' => now()->subDay()]);
    $before   = Sale::query()->get()->toArray();
    $response = $this->getJson($this->url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.filter.storeId', $this->store->id)
        ->assertJsonPath('data.salesAmount', 21000)
        ->assertJsonPath('data.salesCount', 6)
        ->assertJsonPath('data.averageReceipt', 3500)
        ->assertJsonPath('data.profit', 19800)
        ->assertJsonPath('data.changePercent', fn (float $value): bool => abs($value - 110) < 0.0001)
        ->assertJsonCount(24, 'data.trend')
        ->assertJsonPath('data.trend.12.amount', 21000)
        ->assertJsonPath('data.topProducts.0.quantity', 12)
        ->assertJsonPath('data.topProducts.0.amount', 21000);
    $payments = collect($response->json('data.payments'))->pluck('amount', 'key')->all();
    expect($payments)->toEqual(['cash' => 3500, 'card' => 5500, 'transfer' => 3000, 'debt' => 7000, 'partial' => 2000]);
    expect(Sale::query()->get()->toArray())->toBe($before);
});

it('matches existing web net revenue and profit for returns and exchanges in the selected store', function (): void {
    ($this->sale)();
    foreach ([['return', 1, 500], ['exchange_in', 1, 400], ['exchange_out', -1, 600]] as [$type, $quantity, $price]) {
        InventoryAdjustment::query()->create(['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price, 'adjustment_type' => $type, 'created_at' => now()]);
    }
    CashTransaction::query()->create(['amount' => 500, 'direction' => 'out', 'reason' => 'return', 'created_at' => now()]);
    $data = $this->getJson($this->url)->assertOk()->assertJsonPath('data.salesAmount', 700)
        ->assertJsonPath('data.profit', 600)->json('data');
    $widget             = app(SalesStatsOverview::class);
    $widget->start_date = '2026-10-17';
    $widget->end_date   = '2026-10-17';
    $cards              = (new ReflectionMethod($widget, 'getCards'))->invoke($widget);
    expect($cards[0]->getValue())->toBe(number_format($data['salesAmount']) . " so'm")
        ->and($cards[1]->getValue())->toBe(number_format($data['profit']) . " so'm");
});

it('isolates stores including adjustments and rejects stale store requests', function (): void {
    ($this->sale)();
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    ($this->sale)(['store_id' => $otherStore->id, 'total_amount' => 99999]);
    $otherProduct = Product::factory()->create(['store_id' => $otherStore->id]);
    InventoryAdjustment::query()->create(['product_id' => $otherProduct->id, 'quantity' => 1, 'unit_price' => 99999, 'adjustment_type' => 'return', 'created_at' => now()]);
    CashTransaction::query()->create(['amount' => 99999, 'direction' => 'out', 'reason' => 'return', 'created_at' => now()]);
    $this->getJson($this->url . '&storeId=' . $otherStore->id)->assertOk()
        ->assertJsonPath('data.salesAmount', 1000)->assertJsonPath('data.salesCount', 1)->assertJsonPath('data.profit', 800);
    $this->withHeader('X-Mobile-Store', (string) $otherStore->id)->getJson($this->url)->assertConflict();
});

it('requires the existing statistics permission even for cashiers', function (): void {
    $this->user->revokePermissionTo('View:SalesStatsOverview');
    $this->getJson($this->url)->assertForbidden();
    $this->getJson('/mobile/api/v1/session')->assertOk()->assertJsonPath('permissions.sales_statistics', false);
});

it('requires authentication and membership in the selected store', function (): void {
    $this->user->stores()->detach();
    $this->getJson($this->url)->assertForbidden();
    auth()->logout();
    $this->getJson($this->url)->assertUnauthorized();
});

it('respects the existing top products widget permission', function (): void {
    ($this->sale)();
    $this->user->revokePermissionTo('View:TopSellingProductsChart');
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.topProducts', []);
});

it('returns real empty data without fabricated growth or division errors', function (): void {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.salesAmount', 0)
        ->assertJsonPath('data.salesCount', 0)->assertJsonPath('data.profit', 0)
        ->assertJsonPath('data.changePercent', null)->assertJsonPath('data.marginPercent', null)
        ->assertJsonPath('data.payments', [])->assertJsonPath('data.topProducts', []);
});

it('groups daily trends and includes both date boundaries', function (): void {
    ($this->sale)(['created_at' => '2026-10-12 00:00:00']);
    ($this->sale)(['created_at' => '2026-10-17 23:59:59']);
    ($this->sale)(['created_at' => '2026-10-11 23:59:59']);
    $this->getJson('/mobile/api/v1/sales/statistics?period=week&start=2026-10-12&end=2026-10-17')
        ->assertOk()->assertJsonPath('data.salesAmount', 2000)->assertJsonCount(6, 'data.trend')
        ->assertJsonPath('data.trend.0.amount', 1000)->assertJsonPath('data.trend.5.amount', 1000);
});

it('validates dates and bounds report size', function (string $query): void {
    $this->getJson('/mobile/api/v1/sales/statistics?' . $query)->assertUnprocessable();
})->with([
    'invalid period'  => 'period=invalid&start=2026-10-17&end=2026-10-17',
    'invalid date'    => 'period=range&start=bad&end=2026-10-17',
    'reversed dates'  => 'period=range&start=2026-10-17&end=2026-10-16',
    'future date'     => 'period=range&start=2026-10-17&end=2026-10-18',
    'excessive range' => 'period=range&start=2020-01-01&end=2026-10-17',
]);
