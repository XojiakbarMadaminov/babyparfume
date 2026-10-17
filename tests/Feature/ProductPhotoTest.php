<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use App\Services\ProductPhotoService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\GalleryProducts;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['media-library.disk_name' => 'public', 'media-library.image_driver' => 'gd']);
    Storage::fake('public');
    Http::preventStrayRequests();
    $this->store = Store::query()->create(['name' => 'Asosiy', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['ViewAny:Product', 'View:Product', 'Create:Product', 'Update:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->stock = Stock::query()->create(['name' => 'Asosiy', 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->payload = [
        'store_id' => $this->store->id, 'name' => 'Rasmli mahsulot', 'barcode' => '1234567890123',
        'type'     => 'package', 'initial_price' => 20000, 'price' => 25000, 'stocks' => [],
    ];
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('creates a product photo and small previews without a queue or external optimizers', function (): void {
    $response = $this->post('/mobile/api/v1/products', [
        ...$this->payload, 'stocks' => '[]', 'photo' => UploadedFile::fake()->image('camera.jpg', 1800, 1200),
    ], ['Accept' => 'application/json'])->assertSuccessful();
    $product = Product::query()->findOrFail($response->json('data.id'));
    $media   = $product->getFirstMedia(Product::IMAGE_COLLECTION);
    Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    expect($media->getCustomProperty('original_deleted'))->toBeTrue();
    foreach ([Product::OPTIMIZED_CONVERSION => [960, 640], Product::THUMBNAIL_CONVERSION => [160, 107]] as $conversion => $dimensions) {
        expect($media->hasGeneratedConversion($conversion))->toBeTrue();
        $image = getimagesize($media->getPath($conversion));
        expect([$image[0], $image[1]])->toBe($dimensions);
    }
    $response->assertJsonPath('data.image', $product->getPrimaryImageUrl())
        ->assertJsonPath('data.thumbnail', $product->getThumbnailUrl());
    expect($product->getThumbnailUrl())->not->toBe($product->getPrimaryImageUrl());
    $this->getJson('/mobile/api/v1/products')->assertOk()->assertJsonPath('data.0.thumbnail', $product->getThumbnailUrl());
});

it('accepts multipart size inventories alongside the image', function (): void {
    $this->post('/mobile/api/v1/products', [
        ...$this->payload, 'type' => 'size',
        'sizes' => json_encode([['size' => 38, 'stocks' => [['stock_id' => $this->stock->id, 'quantity' => 4]]]]),
        'photo' => UploadedFile::fake()->image('camera.png'),
    ], ['Accept' => 'application/json'])->assertSuccessful()->assertJsonPath('data.quantity', 4);
    expect(Product::query()->firstOrFail()->getMedia(Product::IMAGE_COLLECTION))->toHaveCount(1);
});

it('still creates products without a photo', function (): void {
    $this->postJson('/mobile/api/v1/products', $this->payload)->assertSuccessful()
        ->assertJsonPath('data.image', null)->assertJsonPath('data.thumbnail', null);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('replaces a photo and deletes the old original and conversions', function (): void {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $this->payload['barcode']]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('old.jpg'));
    $previous = Storage::disk('public')->allFiles();
    $this->post('/mobile/api/v1/products/' . $product->id, [
        ...$this->payload, 'stocks' => '[]', 'photo' => UploadedFile::fake()->image('new.png'),
    ], ['Accept' => 'application/json'])->assertOk();
    expect($product->fresh()->getMedia(Product::IMAGE_COLLECTION))->toHaveCount(1);
    foreach ($previous as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('preserves the photo when editing other fields and removes it only when requested', function (): void {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $this->payload['barcode']]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('photo.png'));
    $url = $product->getPrimaryImageUrl();
    $this->patchJson('/mobile/api/v1/products/' . $product->id, $this->payload)->assertOk()->assertJsonPath('data.image', $url);
    $this->patchJson('/mobile/api/v1/products/' . $product->id, [...$this->payload, 'remove_image' => true])
        ->assertOk()->assertJsonPath('data.image', null)->assertJsonPath('data.thumbnail', null);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('rejects invalid product photos on create and update without changing files', function (string $kind): void {
    $photo = match ($kind) {
        'text'       => UploadedFile::fake()->create('image.txt', 1, 'text/plain'),
        'size'       => UploadedFile::fake()->image('large.jpg')->size(1025),
        'dimensions' => UploadedFile::fake()->image('wide.png', 2049, 10),
        'svg'        => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        'gif'        => UploadedFile::fake()->image('animated.gif'),
    };
    $this->post('/mobile/api/v1/products', [...$this->payload, 'photo' => $photo], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect(Product::query()->count())->toBe(0);
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $this->payload['barcode']]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('valid.jpg'));
    $paths = Storage::disk('public')->allFiles();
    $this->post('/mobile/api/v1/products/' . $product->id, [...$this->payload, 'photo' => $photo], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect(Storage::disk('public')->allFiles())->toBe($paths);
})->with(['text', 'size', 'dimensions', 'svg', 'gif']);

it('protects photo changes with product permissions and store access', function (): void {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $this->payload['barcode']]);
    $this->user->revokePermissionTo('Update:Product');
    $payload = [...$this->payload, 'photo' => UploadedFile::fake()->image('photo.jpg')];
    $this->post('/mobile/api/v1/products/' . $product->id, $payload, ['Accept' => 'application/json'])->assertForbidden();
    $this->user->givePermissionTo('Update:Product');
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->user->stores()->attach($otherStore);
    $this->user->update(['current_store_id' => $otherStore->id]);
    $this->post('/mobile/api/v1/products/' . $product->id, $payload, ['Accept' => 'application/json'])->assertNotFound();
    auth()->logout();
    $this->post('/mobile/api/v1/products', $payload, ['Accept' => 'application/json'])->assertUnauthorized();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('does not store photos when other product fields are invalid', function (): void {
    $this->post('/mobile/api/v1/products', [
        ...$this->payload, 'name' => '', 'photo' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(Product::query()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('falls back to existing images when conversions have not been generated', function (): void {
    Event::fake([ConversionHasBeenCompletedEvent::class]);
    $product = Product::factory()->create(['store_id' => $this->store->id]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('photo.jpg'));
    $media = $product->getFirstMedia(Product::IMAGE_COLLECTION);
    $media->update(['generated_conversions' => []]);
    $product->refresh();
    expect($product->getPrimaryImageUrl())->toBe($media->getUrl())
        ->and($product->getThumbnailUrl())->toBe($media->getUrl());
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('uploads replaces displays and removes product photos through Filament', function (): void {
    $this->withoutVite();
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'          => 'Web mahsulot', 'barcode' => $this->payload['barcode'], 'type' => 'package',
            'initial_price' => 20000, 'price' => 25000, 'photo' => UploadedFile::fake()->image('web.jpg'),
        ])->call('create')->assertHasNoFormErrors();
    $product = Product::query()->firstOrFail();
    expect($product->hasMedia(Product::IMAGE_COLLECTION))->toBeTrue();
    Livewire::test(ViewProduct::class, ['record' => $product->id])->assertSee($product->getPrimaryImageUrl(), false);
    Livewire::test(ListProducts::class)->assertSee($product->getThumbnailUrl(), false);
    $previous = Storage::disk('public')->allFiles();
    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['photo' => UploadedFile::fake()->image('replacement.png')])
        ->call('save')->assertHasNoFormErrors();
    foreach ($previous as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['remove_image' => true])->call('save')->assertHasNoFormErrors();
    expect($product->fresh()->hasMedia(Product::IMAGE_COLLECTION))->toBeFalse();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('rejects invalid uploads in the Filament form', function (): void {
    $this->withoutVite();
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'          => 'Web mahsulot', 'barcode' => $this->payload['barcode'], 'type' => 'package',
            'initial_price' => 20000, 'price' => 25000, 'photo' => UploadedFile::fake()->create('bad.txt', 1),
        ])->call('create')->assertHasFormErrors(['photo']);
    expect(Product::query()->count())->toBe(0);
});

it('keeps the browser compressed original usable when the image driver is unavailable', function (): void {
    config(['media-library.image_driver' => 'unavailable']);
    $response = $this->post('/mobile/api/v1/products', [
        ...$this->payload, 'photo' => UploadedFile::fake()->image('small.jpg', 640, 480),
    ], ['Accept' => 'application/json'])->assertSuccessful();
    $product = Product::query()->findOrFail($response->json('data.id'));
    $media   = $product->getFirstMedia(Product::IMAGE_COLLECTION);
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    $response->assertJsonPath('data.image', $media->getUrl())->assertJsonPath('data.thumbnail', $media->getUrl());
});

it('uses the configured media disk for originals and previews', function (): void {
    config(['media-library.disk_name' => 'photos']);
    Storage::fake('photos');
    $response = $this->post('/mobile/api/v1/products', [
        ...$this->payload, 'photo' => UploadedFile::fake()->image('small.jpg'),
    ], ['Accept' => 'application/json'])->assertSuccessful();
    $media = Product::query()->findOrFail($response->json('data.id'))->getFirstMedia(Product::IMAGE_COLLECTION);
    expect($media->disk)->toBe('photos')->and($media->conversions_disk)->toBe('photos');
    Storage::disk('photos')->assertExists($media->getPathRelativeToRoot(Product::THUMBNAIL_CONVERSION));
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('cleans up failed image replacements and preserves the previous photo and product details', function (): void {
    $this->withoutExceptionHandling();
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $this->payload['barcode']]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('previous.jpg'));
    $files = Storage::disk('public')->allFiles();
    $url   = $product->getPrimaryImageUrl();
    Event::listen(ConversionHasBeenCompletedEvent::class, function (ConversionHasBeenCompletedEvent $event): void {
        if ($event->conversion->getName() === Product::THUMBNAIL_CONVERSION) {
            throw new RuntimeException('Image processing failed');
        }
    });
    expect(fn () => $this->post('/mobile/api/v1/products/' . $product->id, [
        ...$this->payload, 'photo' => UploadedFile::fake()->image('replacement.jpg'),
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Image processing failed');
    expect(Storage::disk('public')->allFiles())->toBe($files)
        ->and($product->fresh()->name)->toBe($product->name)
        ->and($product->fresh()->getPrimaryImageUrl())->toBe($url)
        ->and($product->fresh()->getMedia(Product::IMAGE_COLLECTION))->toHaveCount(1);
});

it('serves image urls from the current site even when the app url points at localhost', function (): void {
    config(['app.url' => 'http://localhost']);
    $product = Product::factory()->create(['store_id' => $this->store->id]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('photo.jpg'));
    expect($product->getPrimaryImageUrl())->toStartWith('/storage/')
        ->and($product->getThumbnailUrl())->toStartWith('/storage/');
    $this->getJson('http://shop.example:8080/mobile/api/v1/products/' . $product->id)
        ->assertOk()->assertJsonPath('data.thumbnail', $product->getThumbnailUrl());
    $this->withoutVite();
    Livewire::test(ListProducts::class)->assertSee($product->getThumbnailUrl(), false);
    $this->user->givePermissionTo(Permission::findOrCreate('View:Pos', 'web'));
    Livewire::test(Sales::class)->assertSee($product->getThumbnailUrl(), false);
    Livewire::test(Pos::class)->set('search', $product->name)->assertSee($product->getThumbnailUrl(), false);
});

it('paginates mobile products in groups of ten', function (): void {
    Product::factory()->count(11)->create(['store_id' => $this->store->id]);
    $this->getJson('/mobile/api/v1/products')->assertOk()
        ->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 2);
    $this->getJson('/mobile/api/v1/products?page=2')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
});

it('paginates mobile sales products in groups of ten', function (): void {
    $this->withoutVite();
    $this->user->givePermissionTo(Permission::findOrCreate('View:Pos', 'web'));
    Product::factory()->count(11)->create(['store_id' => $this->store->id]);
    Livewire::test(Sales::class)
        ->assertViewHas('mobileProducts', fn ($products): bool => $products->perPage() === 10 && $products->count() === 10)
        ->call('nextPage')
        ->assertViewHas('mobileProducts', fn ($products): bool => $products->currentPage() === 2 && $products->count() === 1);
});

it('paginates the admin product table and gallery in groups of ten', function (): void {
    $this->withoutVite();
    Product::factory()->count(11)->create(['store_id' => $this->store->id]);
    $page = Livewire::test(ListProducts::class)->assertSet('tableRecordsPerPage', 10);
    expect($page->instance()->getTableRecords())->toHaveCount(10);
    $page->call('nextPage');
    expect($page->instance()->getTableRecords())->toHaveCount(1);
    Livewire::test(GalleryProducts::class)
        ->assertViewHas('products', fn ($products): bool => $products->perPage() === 10 && $products->count() === 10)
        ->call('nextPage')
        ->assertViewHas('products', fn ($products): bool => $products->currentPage() === 2 && $products->count() === 1);
});

it('opens the full product image in a modal from the admin product list', function (): void {
    $this->withoutVite();
    $product = Product::factory()->create(['store_id' => $this->store->id]);
    app(ProductPhotoService::class)->replace($product, UploadedFile::fake()->image('photo.jpg'));
    $media     = $product->getFirstMedia(Product::IMAGE_COLLECTION);
    $fullImage = basename($media->getPath(Product::OPTIMIZED_CONVERSION));
    $page      = Livewire::test(ListProducts::class)
        ->assertDontSee($fullImage, false)
        ->assertTableActionExists('zoom_image')
        ->mountTableAction('zoom_image', $product->id)
        ->assertSet('mountedActions.0.name', 'zoom_image')
        ->assertNoRedirect();
    expect($page->instance()->getMountedAction()->getModalContent()->render())
        ->toContain($fullImage, $product->name);
});

it('uses the configured hosting photo directory for uploaded product images', function (): void {
    $root     = Storage::fake('photos')->path('');
    $previous = Illuminate\Support\Env::get('PHOTOS_PATH');
    Illuminate\Support\Env::getRepository()->set('PHOTOS_PATH', $root);

    try {
        $filesystems = require config_path('filesystems.php');
        config(['filesystems.disks.photos' => $filesystems['disks']['photos'], 'media-library.disk_name' => 'photos']);
        Storage::forgetDisk('photos');
        $response = $this->post('/mobile/api/v1/products', [
            ...$this->payload, 'photo' => UploadedFile::fake()->image('hosting.jpg'),
        ], ['Accept' => 'application/json'])->assertSuccessful();
        $media = Product::query()->findOrFail($response->json('data.id'))->getFirstMedia(Product::IMAGE_COLLECTION);
        expect($media->getPath(Product::THUMBNAIL_CONVERSION))->toStartWith($root)
            ->and($media->getUrl(Product::THUMBNAIL_CONVERSION))->toStartWith('/photos/');
        Storage::disk('photos')->assertExists($media->getPathRelativeToRoot(Product::THUMBNAIL_CONVERSION));
    } finally {
        if ($previous === null) {
            Illuminate\Support\Env::getRepository()->clear('PHOTOS_PATH');
        } else {
            Illuminate\Support\Env::getRepository()->set('PHOTOS_PATH', $previous);
        }
    }
});

it('defaults the photo directory to the application public folder without a hosting override', function (): void {
    $previous = Illuminate\Support\Env::get('PHOTOS_PATH');
    Illuminate\Support\Env::getRepository()->clear('PHOTOS_PATH');

    try {
        $filesystems = require config_path('filesystems.php');
        expect($filesystems['disks']['photos']['root'])->toBe(public_path('photos'));
    } finally {
        if ($previous !== null) {
            Illuminate\Support\Env::getRepository()->set('PHOTOS_PATH', $previous);
        }
    }
});
