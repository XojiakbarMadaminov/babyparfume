<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Str;
use App\Services\FlutterSaleService;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps original receipt prices discounts cash and change after synchronization', function (): void {
    config(['printing.enabled' => true]);
    $store = Store::query()->create(['name' => 'Receipt test', 'address' => 'Test', 'phone' => '+998901234567']);
    $stock = Stock::query()->create(['name' => 'Test stock', 'is_active' => true]);
    $stock->stores()->attach($store);
    $user = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);
    $user->givePermissionTo(Permission::findOrCreate('View:Pos', 'web'));
    $this->actingAs($user);
    $product = Product::factory()->create(['store_id' => $store->id, 'type' => 'package', 'name' => 'Asl nom', 'price' => 15000, 'initial_price' => 5000]);
    $product->productStocks()->create(['stock_id' => $stock->id, 'quantity' => 3]);
    app(FlutterSaleService::class)->createSale($user, $store, [
        'local_sale_id' => (string) Str::uuid(), 'receipt_discount' => '2000', 'items' => [
            ['product_id' => $product->id, 'stock_id' => $stock->id, 'quantity' => 1, 'unit_price' => '15000', 'discount' => '1000']],
        'payment' => ['type' => 'cash', 'cash' => '12000', 'card' => '0', 'received_cash' => '20000'],
    ]);
    $sale = Sale::withoutGlobalScopes()->firstOrFail();
    $meta = $sale->flutter_receipt_meta;
    expect($sale->total_amount)->toEqual(12000)->and($meta['items'][0]['price'])->toBe('15000')
        ->and($meta['items'][0]['discount'])->toBe('1000')->and($meta['receipt_discount'])->toBe('2000')
        ->and($meta['received_cash'])->toBe('20000')->and($meta['change'])->toBe('8000');
    $product->update(['name' => 'Yangi nom']);
    expect($sale->fresh()->flutter_receipt_meta['items'][0]['name'])->toBe('Asl nom');
    expect($product->productStocks()->first()->quantity)->toBe(2);
});
