<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Debtor;
use Livewire\Livewire;
use Illuminate\Support\Str;
use App\Services\SmsService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use App\Services\TelegramNotifier;
use App\Services\DebtorPhotoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Debtors\Pages\EditDebtor;
use App\Filament\Resources\Debtors\Pages\ViewDebtor;
use App\Filament\Resources\Debtors\Pages\CreateDebtor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->mock(SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    $this->store = Store::query()->create(['name' => 'Asosiy', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['ViewAny:Debtor', 'View:Debtor', 'Create:Debtor', 'Update:Debtor'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    $this->payload = [
        'request_id' => (string) Str::uuid(), 'store_id' => $this->store->id,
        'full_name'  => 'Rasmli mijoz', 'phone' => '+998901234567',
        'amount'     => 100000, 'date' => today()->toDateString(), 'note' => 'Rasm',
    ];
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('stores a mobile photo privately and includes its url only in details', function (): void {
    $photo    = UploadedFile::fake()->image('qarzdor.png', 180, 240);
    $response = $this->post('/mobile/api/v1/debtors', [...$this->payload, 'photo' => $photo], ['Accept' => 'application/json'])->assertSuccessful();
    $debtor   = Debtor::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists($debtor->photo_path);
    expect($debtor->toArray())->not->toHaveKey('photo_path');
    expect(Storage::disk('local')->size($debtor->photo_path))->toBeLessThanOrEqual($photo->getSize());
    $url = route('debtors.photo', $debtor, false);
    $this->getJson('/mobile/api/v1/debtors/' . $debtor->id)->assertOk()->assertJsonPath('data.photo_url', $url);
    $this->getJson('/mobile/api/v1/debtors')->assertOk()->assertJsonMissingPath('data.0.photo_url')->assertJsonMissingPath('data.0.photo_path');
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('keeps image dimensions and pixels unchanged after lossless optimization', function (): void {
    $photo  = UploadedFile::fake()->image('camera.png', 100, 120);
    $path   = app(DebtorPhotoService::class)->store($photo);
    $before = imagecreatefromstring(file_get_contents($photo->getRealPath()));
    $after  = imagecreatefromstring(Storage::disk('local')->get($path));
    expect(imagesx($after))->toBe(imagesx($before));
    expect(imagesy($after))->toBe(imagesy($before));
    for ($row = 0; $row < imagesy($before); $row++) {
        for ($column = 0; $column < imagesx($before); $column++) {
            expect(imagecolorsforindex($after, imagecolorat($after, $column, $row)))
                ->toBe(imagecolorsforindex($before, imagecolorat($before, $column, $row)));
        }
    }
    imagedestroy($before);
    imagedestroy($after);
});

it('resizes images exceeding 1920px and compresses to webp with GD', function (): void {
    $photo = UploadedFile::fake()->image('large_camera.jpg', 2400, 3200);
    $path  = app(DebtorPhotoService::class)->store($photo);
    expect($path)->toEndWith('.webp');
    $after = imagecreatefromstring(Storage::disk('local')->get($path));
    expect(imagesx($after))->toBeLessThanOrEqual(1920);
    expect(imagesy($after))->toBeLessThanOrEqual(1920);
    expect(imagesx($after))->toBe(1440);
    expect(imagesy($after))->toBe(1920);
    imagedestroy($after);
});

it('replays the same photo request without creating duplicate files and rejects changed photos', function (): void {
    $photo   = UploadedFile::fake()->image('camera.png', 20, 30);
    $payload = [...$this->payload, 'photo' => $photo];
    $first   = $this->post('/mobile/api/v1/debtors', $payload, ['Accept' => 'application/json'])->assertSuccessful()->json('data.id');
    $this->post('/mobile/api/v1/debtors', $payload, ['Accept' => 'application/json'])->assertSuccessful()->assertJsonPath('data.id', $first);
    $this->post('/mobile/api/v1/debtors', [...$payload, 'photo' => UploadedFile::fake()->image('other.png', 30, 40)], ['Accept' => 'application/json'])->assertConflict();
    expect(Storage::disk('local')->allFiles('debtor-photos'))->toHaveCount(1);
    expect(Debtor::query()->count())->toBe(1);
});

it('rejects invalid photos before creating the debtor', function (string $kind): void {
    $photo = match ($kind) {
        'text'       => UploadedFile::fake()->create('photo.txt', 1, 'text/plain'),
        'large'      => UploadedFile::fake()->image('large.jpg')->size(10241),
        'dimensions' => UploadedFile::fake()->image('wide.png', 6001, 1),
        'svg'        => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    };
    $this->post('/mobile/api/v1/debtors', [...$this->payload, 'photo' => $photo], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect(Debtor::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['text', 'large', 'dimensions', 'svg']);

it('cleans up a photo when debtor creation is rejected', function (): void {
    $this->postJson('/mobile/api/v1/debtors', $this->payload)->assertSuccessful();
    $this->post('/mobile/api/v1/debtors', [...$this->payload, 'request_id' => (string) Str::uuid(), 'photo' => UploadedFile::fake()->image('photo.png')], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('protects photos from guests, missing permissions and other stores', function (): void {
    $response = $this->post('/mobile/api/v1/debtors', [...$this->payload, 'photo' => UploadedFile::fake()->image('photo.jpg')], ['Accept' => 'application/json'])->assertSuccessful();
    $debtor   = Debtor::query()->findOrFail($response->json('data.id'));
    $url      = route('debtors.photo', $debtor, false);
    $this->user->revokePermissionTo('View:Debtor');
    $this->getJson($url)->assertForbidden();
    $this->user->givePermissionTo('View:Debtor');
    $otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->user->update(['current_store_id' => $otherStore->id]);
    $this->getJson($url)->assertNotFound();
    $this->user->update(['current_store_id' => $this->store->id]);
    $this->user->stores()->detach();
    $this->getJson($url)->assertForbidden();
    auth()->logout();
    $this->getJson($url)->assertUnauthorized();
});

it('allows creation without a photo and returns not found for a missing photo', function (): void {
    $response = $this->postJson('/mobile/api/v1/debtors', $this->payload)->assertSuccessful();
    $this->getJson('/mobile/api/v1/debtors/' . $response->json('data.id'))->assertJsonPath('data.photo_url', null);
    $this->getJson(route('debtors.photo', $response->json('data.id'), false))->assertNotFound();
});

it('creates a debtor with a photo through the web form and displays its viewer', function (): void {
    $this->withoutVite();
    Livewire::test(CreateDebtor::class)
        ->fillForm(['full_name' => 'Web mijoz', 'phone' => '901234567', 'amount' => 100000, 'date' => today()->toDateString(), 'photo' => UploadedFile::fake()->image('web.png')])
        ->call('create')
        ->assertHasNoFormErrors();
    $debtor = Debtor::query()->firstOrFail();
    expect($debtor->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($debtor->photo_path);
    Livewire::test(ViewDebtor::class, ['record' => $debtor->id])->assertSee('debtor-photo-viewer', false);
    $previous = $debtor->photo_path;
    Livewire::test(EditDebtor::class, ['record' => $debtor->id])
        ->fillForm(['photo' => UploadedFile::fake()->image('replacement.jpg')])
        ->call('save')->assertHasNoFormErrors();
    expect($debtor->refresh()->photo_path)->not->toBe($previous);
    Storage::disk('local')->assertMissing($previous);
    Storage::disk('local')->assertExists($debtor->photo_path);
});

it('rejects invalid web uploads', function (): void {
    $this->withoutVite();
    Livewire::test(CreateDebtor::class)
        ->fillForm(['full_name' => 'Web mijoz', 'phone' => '901234567', 'amount' => 100000, 'date' => today()->toDateString(), 'photo' => UploadedFile::fake()->create('invalid.txt', 1)])
        ->call('create')->assertHasFormErrors(['photo']);
    expect(Debtor::query()->count())->toBe(0);
});
