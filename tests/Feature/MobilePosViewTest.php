<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\ViewErrorBag;

function mobilePosView(array $overrides = []): string
{
    return view('mobile.partials.sales', array_replace([
        'mobileProducts'     => collect(),
        'mobileCategories'   => collect(),
        'mobileCartProducts' => collect(),
        'mobileStocks'       => collect([1 => 'Asosiy']),
        'mobileCategory'     => null,
        'activeCarts'        => [1 => ['qty' => 0, 'amount' => 0]],
        'activeCartId'       => 1,
        'cart'               => [],
        'totals'             => ['qty' => 0, 'amount' => 0],
        'search'             => '',
        'clients'            => collect(),
        'selectedClientId'   => null,
        'saleWithoutClient'  => true,
        'paymentType'        => 'cash',
        'showReceipt'        => false,
        'receiptData'        => [],
        'errors'             => new ViewErrorBag,
    ], $overrides))->render();
}

function mobilePosProduct(string $type = 'package'): Product
{
    return Product::factory()->make([
        'id'          => 17,
        'name'        => 'Dior Sauvage',
        'price'       => 450000,
        'category_id' => 3,
        'type'        => $type,
    ])->setRelation('category', new Category(['id' => 3, 'name' => 'Atir']))
        ->setRelation('media', collect());
}

function mobilePosCart(): array
{
    return [17 => ['id' => 17, 'name' => 'Dior Sauvage', 'price' => 450000, 'qty' => 2, 'stock_id' => 1]];
}

it('renders the search-first empty state without a sticky cart', function (): void {
    $html = mobilePosView();

    expect($html)->toContain('Mahsulot qo‘shishni boshlang', 'Savat bo‘sh', 'wire:model.live.debounce.350ms="search"')
        ->not->toContain('class="mp-cart-bar"', '<table');
});

it('renders searchable product cards and category chips with existing add actions', function (): void {
    $product = mobilePosProduct();
    $html    = mobilePosView(['mobileProducts' => collect([$product]), 'mobileCategories' => collect([$product->category])]);

    expect($html)->toContain('Dior Sauvage', '450 000', 'mp-category-3', 'addProduct(17)', 'Skanerlash')
        ->not->toContain('<table');
});

it('renders no-results guidance for an unsuccessful search', function (): void {
    expect(mobilePosView(['search' => 'unknown']))->toContain('Mahsulot topilmadi', 'Qidiruvni o‘zgartiring yoki barcode skanerlang.');
});

it('keeps quantity stock price and remove controls bound to existing server methods', function (): void {
    $html = mobilePosView([
        'cart'               => mobilePosCart(),
        'mobileCartProducts' => collect([17 => mobilePosProduct()]),
        'totals'             => ['qty' => 2, 'amount' => 900000],
    ]);

    expect($html)->toContain('class="mp-cart-bar"', '900 000', '2 mahsulot', 'updatePackageQty(17, 1)', 'updatePackageQty(17, 3)', 'wire:click="remove(17)"', 'wire:confirm=', 'updatePrice(17')
        ->not->toContain('updateStock(17', 'Sklad');
});

it('opens cart-wide discounts separately from individual product discounts', function (): void {
    $html = mobilePosView([
        'cart'               => mobilePosCart(),
        'mobileCartProducts' => collect([17 => mobilePosProduct()]),
        'totals'             => ['qty' => 2, 'amount' => 810000],
    ]);

    expect($html)->toContain('@click="openCartDiscount()"', 'openDiscount(17,', "this.discMode = 'item'", "this.discMode = 'receipt'", '$wire.applyReceiptDiscount(amount)', '$wire.resetReceiptDiscount()', '$wire.updatePrice(this.discId, newPrice)', 'Barcha mahsulotlarga chegirma')
        ->not->toContain('openFirstDiscount');
});

it('uses the existing size modal action instead of bypassing size-based quantity rules', function (): void {
    $html = mobilePosView(['cart' => mobilePosCart(), 'mobileCartProducts' => collect([17 => mobilePosProduct('size')])]);

    expect($html)->toContain('chooseSizes(17)', '$wire.getProductSizes(id)', '$wire.updateSizes(sizeProduct.id, quantities)')
        ->not->toContain('updatePackageQty(17,');
});

it('renders existing payment-specific fields without introducing payment calculations', function (string $method, array $fields): void {
    $html = mobilePosView(['paymentType' => $method, 'selectedClientId' => 4, 'saleWithoutClient' => false]);

    foreach ($fields as $field) {
        expect($html)->toContain($field);
    }

    expect($html)->toContain("selectPaymentType('{$method}')", '$wire.checkout()', 'Saqlanmoqda...', 'submitting || busy');
})->with([
    'cash'     => ['cash', ['Qabul qilindi', 'Qaytim']],
    'card'     => ['card', ['Karta']],
    'transfer' => ['transfer', ['O‘tkazma']],
    'debt'     => ['debt', ['wire:model.blur="paymentNote"']],
    'partial'  => ['partial', ['wire:model.blur="partialPaymentAmount"']],
    'mixed'    => ['mixed', ['wire:model.blur="mixedPayment.card"', 'wire:model.blur="mixedPayment.cash"']],
    'preorder' => ['preorder', ['Oldindan buyurtma']],
]);

it('renders customer selection using the existing client action', function (): void {
    $client = Client::factory()->make(['id' => 4, 'full_name' => 'Anvar Karimov', 'phone' => '+998901234567']);
    $html   = mobilePosView(['clients' => collect([$client]), 'selectedClientId' => 4]);

    expect($html)->toContain('Anvar Karimov', '$wire.selectClient(4)', 'wire:model.live.debounce.350ms="searchClient"');
});

it('renders a receipt-backed success state only after server success', function (): void {
    $html = mobilePosView([
        'showReceipt' => true,
        'receiptData' => ['totals' => ['qty' => 3, 'amount' => 1280000], 'items' => [], 'store_name' => 'Million'],
    ]);

    expect($html)->toContain('Savdo muvaffaqiyatli', '1 280 000', '3 mahsulot', 'Chekni ko‘rish', '+ Yangi savdo', '$wire.closeReceipt()')
        ->not->toContain('class="mp-cart-bar"');
});

it('preserves native dialog state across livewire updates', function (): void {
    $html = mobilePosView();

    foreach (['cart', 'customer', 'price', 'sizes', 'camera'] as $dialog) {
        expect($html)->toContain("x-ref=\"{$dialog}\" wire:ignore.self");
    }

    expect($html)->toContain('pos-mobile-notices');
});

it('preserves fractional money amounts and spaces as thousand separators', function (): void {
    $html = mobilePosView(['totals' => ['qty' => 1, 'amount' => 1280000.5]]);

    expect($html)->toContain('1 280 000.5')->not->toContain('1,280,000');
});

it('does not display general profit in top stats and displays initial price only to authorized users', function (): void {
    $product                = mobilePosProduct();
    $product->initial_price = 350000;

    // Unauthorized user
    $htmlUnauthorized = mobilePosView([
        'mobileProducts'     => collect([$product]),
        'cart'               => mobilePosCart(),
        'mobileCartProducts' => collect([17 => $product]),
        'totals'             => ['qty' => 2, 'amount' => 900000],
        'canSeeCostPrice'    => false,
    ]);

    expect($htmlUnauthorized)
        ->toContain('DONA', 'POZITSIYA', 'SUMMA')
        ->not->toContain('FOYDA')
        ->not->toContain('title="Kelgan narxi"', 'Kelgan narxi:', '350 000', '700 000');

    // Authorized user
    $htmlAuthorized = mobilePosView([
        'mobileProducts'     => collect([$product]),
        'cart'               => mobilePosCart(),
        'mobileCartProducts' => collect([17 => $product]),
        'totals'             => ['qty' => 2, 'amount' => 900000],
        'canSeeCostPrice'    => true,
    ]);

    expect($htmlAuthorized)
        ->toContain('DONA', 'POZITSIYA', 'SUMMA')
        ->not->toContain('FOYDA')
        ->toContain('title="Kelgan narxi"', 'Kelgan narxi: 350 000 so‘m', 'Kelgan narxi: 350 000 so‘m / dona', 'Kelgan: 700 000 so‘m');
});

it('displays zero cost explicitly for authorized users', function (): void {
    $product                = mobilePosProduct();
    $product->initial_price = 0;
    $html                   = mobilePosView([
        'mobileProducts'     => collect([$product]),
        'mobileCartProducts' => collect([17 => $product]),
        'cart'               => mobilePosCart(),
        'totals'             => ['qty' => 2, 'amount' => 900000],
        'canSeeCostPrice'    => true,
    ]);

    expect($html)->toContain('Kelgan narxi: 0 so‘m', 'Kelgan: 0 so‘m');
});

it('does not display receipt discount on product cards and calculates total correctly', function (): void {
    $product1                = mobilePosProduct();
    $product1->id            = 1;
    $product1->name          = 'Carolyn Jacobs';
    $product1->price         = 20000;
    $product1->initial_price = 10000;

    $product2                = mobilePosProduct();
    $product2->id            = 2;
    $product2->name          = 'Leah Schmidt';
    $product2->price         = 30000;
    $product2->initial_price = 25000;

    $cart = [
        1 => ['id' => 1, 'name' => 'Carolyn Jacobs', 'price' => 20000, 'qty' => 1, 'sizes' => [], 'stock_id' => 1],
        2 => ['id' => 2, 'name' => 'Leah Schmidt', 'price' => 30000, 'qty' => 1, 'sizes' => [], 'stock_id' => 1],
    ];

    $html = mobilePosView([
        'mobileProducts'       => collect([$product1, $product2]),
        'mobileCartProducts'   => collect([1 => $product1, 2 => $product2]),
        'cart'                 => $cart,
        'activeCartId'         => 1,
        'cartReceiptDiscounts' => [1 => 10000],
        'totals'               => ['qty' => 2, 'amount' => 40000],
        'canSeeCostPrice'      => true,
    ]);

    expect($html)
        ->not->toContain('mp-disc-badge')
        ->toContain('Carolyn Jacobs', 'Leah Schmidt')
        ->toContain('20 000', '30 000')
        ->toContain('Oraliq summa', '50 000')
        ->toContain('Mahsulotlarga chegirma', '<span>0</span>')
        ->toContain('Butun chekka chegirma', '-10 000')
        ->toContain('Jami', '40 000 UZS');
});
