<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\CashTransaction;
use App\Models\InventoryAdjustment;
use App\Filament\Widgets\SalesStatsOverview;
use App\Services\TelegramDailySummaryService;
use App\Services\MobileSalesStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = Store::query()->create(['name' => 'Million', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    $this->actingAs($this->user);
    $this->product = Product::factory()->create(['store_id' => $this->store->id, 'initial_price' => 70000, 'price' => 100000]);
    $this->stock   = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->sale = Sale::withoutEvents(fn (): Sale => Sale::query()->create([
        'store_id'    => $this->store->id, 'cart_id' => 1, 'total_amount' => 200000,
        'paid_amount' => 200000, 'remaining_amount' => 0, 'payment_type' => 'cash', 'status' => Sale::STATUS_COMPLETED,
    ]));
    $this->itemData = ['product_id' => $this->product->id, 'stock_id' => $this->stock->id, 'quantity' => 2, 'price' => 100000, 'total' => 200000];
    $this->filter   = ['period' => 'today', 'start' => now()->toDateString(), 'end' => now()->toDateString()];
    $this->report   = fn (): array => app(MobileSalesStatisticsService::class)->report($this->store->id, $this->filter, true);
});

it('freezes cost at sale time and uses the new cost only for subsequent sale items', function (): void {
    $oldItem = $this->sale->items()->create($this->itemData);
    expect($oldItem->fresh()->unit_cost)->toBe('70000.00');
    $before = ($this->report)();
    $this->product->update(['initial_price' => 90000]);
    expect(($this->report)())->toBe($before)
        ->and($oldItem->fresh()->unit_cost)->toBe('70000.00')
        ->and($before['profit'])->toBe(60000.0);
    $newItem = $this->sale->items()->create($this->itemData);
    expect($newItem->fresh()->unit_cost)->toBe('90000.00');
    $oldItem->update(['updated_at' => now()->addMinute()]);
    expect($oldItem->fresh()->unit_cost)->toBe('70000.00');
});

it('keeps web and telegram historical profit unchanged after a cost edit', function (): void {
    $this->sale->items()->create($this->itemData);
    $widget         = app(SalesStatsOverview::class);
    $cardsMethod    = new ReflectionMethod($widget, 'getCards');
    $webBefore      = $cardsMethod->invoke($widget)[1]->getValue();
    $telegram       = app(TelegramDailySummaryService::class);
    $summaryMethod  = new ReflectionMethod($telegram, 'collectStoreSummary');
    $telegramBefore = $summaryMethod->invoke($telegram, now()->startOfDay(), now()->endOfDay(), $this->store);
    $this->product->update(['initial_price' => 95000]);
    expect($cardsMethod->invoke($widget)[1]->getValue())->toBe($webBefore)->toBe("60,000 so'm")
        ->and($summaryMethod->invoke($telegram, now()->startOfDay(), now()->endOfDay(), $this->store)['profit'])
        ->toBe($telegramBefore['profit'])->toBe(60000.0);
});

it('freezes return and exchange costs for both mobile and web statistics', function (): void {
    $this->sale->items()->create($this->itemData);
    $adjustments = [];
    foreach ([['return', 1, 100000], ['exchange_in', 1, 120000], ['exchange_out', -1, 150000]] as [$type, $quantity, $price]) {
        $adjustments[] = InventoryAdjustment::query()->create([
            'product_id'      => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price,
            'adjustment_type' => $type, 'created_at' => now(),
        ]);
    }
    CashTransaction::query()->create(['amount' => 100000, 'direction' => 'out', 'reason' => 'return', 'created_at' => now()]);
    $before    = ($this->report)();
    $widget    = app(SalesStatsOverview::class);
    $method    = new ReflectionMethod($widget, 'getCards');
    $webBefore = $method->invoke($widget)[1]->getValue();
    $this->product->update(['initial_price' => 99000]);
    expect(($this->report)())->toBe($before)
        ->and($before['salesAmount'])->toBe(130000.0)
        ->and($before['profit'])->toBe(60000.0)
        ->and($method->invoke($widget)[1]->getValue())->toBe($webBefore);
    foreach ($adjustments as $adjustment) {
        expect($adjustment->fresh()->unit_cost)->toBe('70000.00');
    }
});

it('preserves zero cost snapshots and works with archived products', function (): void {
    $this->product->update(['initial_price' => 0]);
    $item = $this->sale->items()->create($this->itemData);
    $this->product->update(['initial_price' => 90000]);
    $this->product->delete();
    expect($item->fresh()->unit_cost)->toBe('0.00')->and(($this->report)()['profit'])->toBe(200000.0);
});

it('backfills legacy rows once using the available baseline including archived products', function (): void {
    $migration = require database_path('migrations/2026_10_17_145413_add_unit_cost_snapshots_to_sale_items_and_inventory_adjustments.php');
    $migration->down();
    $item       = SaleItem::withoutEvents(fn (): SaleItem => $this->sale->items()->create($this->itemData));
    $adjustment = InventoryAdjustment::withoutEvents(fn (): InventoryAdjustment => InventoryAdjustment::query()->create([
        'product_id'      => $this->product->id, 'quantity' => 1, 'unit_price' => 100000,
        'adjustment_type' => 'return', 'created_at' => now(),
    ]));
    $this->product->delete();
    $migration->up();
    expect($item->fresh()->unit_cost)->toBe('70000.00')->and($adjustment->fresh()->unit_cost)->toBe('70000.00');
    Product::withTrashed()->whereKey($this->product->id)->update(['initial_price' => 98000]);
    expect($item->fresh()->unit_cost)->toBe('70000.00')->and($adjustment->fresh()->unit_cost)->toBe('70000.00');
});
