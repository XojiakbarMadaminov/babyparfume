<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductStock;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchCaseInsensitiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Store $store;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->store = Store::query()->create([
            'name'    => 'Asosiy do‘kon',
            'address' => 'Toshkent',
            'phone'   => '+998901234567',
        ]);

        $this->user = User::factory()->create([
            'current_store_id' => $this->store->id,
        ]);
        $this->user->stores()->attach($this->store);

        foreach (['ViewAny:Product', 'View:Product', 'View:Pos'] as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $adminRole = Role::findOrCreate('super_admin', 'web');
        $this->user->assignRole($adminRole);

        $stock = Stock::query()->create([
            'name'      => 'Ombor 1',
            'is_active' => true,
            'is_main'   => true,
        ]);
        $stock->stores()->attach($this->store);

        $this->product = Product::query()->create([
            'store_id'      => $this->store->id,
            'name'          => '00GRASS UNITAZGA GEL',
            'barcode'       => '4780011223344',
            'price'         => 35000,
            'initial_price' => 25000,
            'type'          => 'package',
        ]);

        ProductStock::query()->create([
            'product_id' => $this->product->id,
            'stock_id'   => $stock->id,
            'quantity'   => 15,
        ]);
    }

    public function test_mobile_products_api_searches_case_insensitively_and_partially(): void
    {
        $this->actingAs($this->user);

        // Lowercase substring
        $response = $this->getJson(route('mobile.api.products', ['q' => 'grass']));
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->product->id, $response->json('data.0.id'));

        // Uppercase substring
        $response = $this->getJson(route('mobile.api.products', ['q' => 'UNITAZGA']));
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        // Middle word in lowercase
        $response = $this->getJson(route('mobile.api.products', ['q' => 'unitazga']));
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        // Multi-word search
        $response = $this->getJson(route('mobile.api.products', ['q' => 'grass gel']));
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        // Partial barcode
        $response = $this->getJson(route('mobile.api.products', ['q' => '112233']));
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_mobile_sales_searches_and_adds_by_partial_and_case_insensitive_name(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');

        // Render search test
        Livewire::test(Sales::class)
            ->set('search', 'grass gel')
            ->assertSee('00GRASS UNITAZGA GEL');

        Livewire::test(Sales::class)
            ->set('search', 'unitazga')
            ->assertSee('00GRASS UNITAZGA GEL');

        // addByBarcode by partial name
        $component = Livewire::test(Sales::class)
            ->call('addByBarcode', 'unitazga');

        $component->assertNotified("Savat #1 ga qo‘shildi");
    }
}
