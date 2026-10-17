<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use App\Services\ProductPhotoService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');
    config(['media-library.disk_name' => 'public']);
    $this->store = Store::query()->create(['name' => 'Asosiy', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['ViewAny:Product', 'View:Product', 'Update:Product', 'Delete:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->product = Product::factory()->create(['store_id' => $this->store->id]);
    $this->url     = '/mobile/api/v1/products/' . $this->product->id;
});

it('reports delete permission independently of edit permission', function (bool $canDelete, bool $canUpdate): void {
    if (!$canDelete) {
        $this->user->revokePermissionTo('Delete:Product');
    }
    if (!$canUpdate) {
        $this->user->revokePermissionTo('Update:Product');
    }
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.can_delete', $canDelete)
        ->assertJsonPath('data.can_update', $canUpdate);
})->with([[true, true], [true, false], [false, true], [false, false]]);

it('soft deletes products without removing stock sizes or uploaded images', function (string $type): void {
    $this->product->update(['type' => $type]);
    $stock = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $stock->stores()->attach($this->store);
    $size      = $type === 'size' ? $this->product->sizes()->create(['size' => 38]) : null;
    $inventory = ($size ?? $this->product)->productStocks()->create(['stock_id' => $stock->id, 'quantity' => 7]);
    app(ProductPhotoService::class)->replace($this->product, UploadedFile::fake()->image('photo.jpg'));
    $files = Storage::disk('public')->allFiles();

    $this->deleteJson($this->url)->assertOk()->assertJsonPath('message', 'Mahsulot o‘chirildi.');
    $this->assertSoftDeleted($this->product);
    expect($inventory->fresh()->quantity)->toEqual(7)
        ->and(Storage::disk('public')->allFiles())->toBe($files);
    if ($size) {
        expect($size->fresh())->not->toBeNull();
    }
    $this->getJson('/mobile/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->url)->assertNotFound();
    $this->getJson('/mobile/api/v1/products/lookup?barcode=' . $this->product->barcode)->assertNotFound();
    $this->deleteJson($this->url)->assertNotFound();
})->with(['package', 'size']);

it('requires deletion permission and authentication', function (): void {
    $this->user->revokePermissionTo('Delete:Product');
    $this->deleteJson($this->url)->assertForbidden();
    $this->assertNotSoftDeleted($this->product);
    auth()->logout();
    $this->deleteJson($this->url)->assertUnauthorized();
    $this->assertNotSoftDeleted($this->product);
});

it('rejects deletion of another store product even when the user belongs to both stores', function (): void {
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->user->stores()->attach($otherStore);
    $product = Product::factory()->create(['store_id' => $otherStore->id]);
    $this->deleteJson('/mobile/api/v1/products/' . $product->id)->assertNotFound();
    $this->assertNotSoftDeleted($product);
});

it('rejects deletion from stale store tabs or an unassigned store', function (): void {
    $this->withHeader('X-Mobile-Store', (string) ($this->store->id + 1))->deleteJson($this->url)->assertConflict();
    $this->assertNotSoftDeleted($this->product);
    $this->user->stores()->detach();
    $this->deleteJson($this->url)->assertForbidden();
    $this->assertNotSoftDeleted($this->product);
});

it('preserves existing sale history when deleting a product', function (): void {
    $stock = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $stock->stores()->attach($this->store);
    $sale = Sale::withoutEvents(fn () => Sale::query()->create(['store_id' => $this->store->id, 'cart_id' => 1]));
    $item = SaleItem::query()->create([
        'sale_id'  => $sale->id, 'product_id' => $this->product->id, 'stock_id' => $stock->id,
        'quantity' => 1, 'price' => 25000,
    ]);
    $this->deleteJson($this->url)->assertOk();
    expect($item->fresh())->not->toBeNull()
        ->and($item->fresh()->product_id)->toBe($this->product->id)
        ->and($sale->fresh())->not->toBeNull();
});
