<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Services\SmsService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use App\Services\TelegramNotifier;
use App\Services\DebtorPhotoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    it('manages debtor update endpoints')->skip('The test database requires the pdo_sqlite PHP extension.');

    return;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->mock(SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);

    $this->store = Store::query()->create(['name' => 'Mobil', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);

    foreach (['ViewAny:Debtor', 'View:Debtor', 'Create:Debtor', 'Update:Debtor'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->client = Client::query()->create([
        'full_name' => 'Eski Qarzdor',
        'phone'     => '+998901112233',
    ]);

    $this->debtor = Debtor::query()->create([
        'store_id'  => $this->store->id,
        'client_id' => $this->client->id,
        'amount'    => 500000,
        'currency'  => 'UZS',
        'date'      => today()->toDateString(),
        'note'      => 'Eski izoh',
    ]);

    $this->updatePayload = [
        'store_id'  => $this->store->id,
        'full_name' => 'Yangilangan Qarzdor',
        'phone'     => '+998909998877',
        'amount'    => 450000,
        'date'      => today()->toDateString(),
        'note'      => 'Yangilangan izoh',
    ];
});

it('returns debtor details with can_update true for authorized user', function (): void {
    $this->getJson('/mobile/api/v1/debtors/' . $this->debtor->id)
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Eski Qarzdor')
        ->assertJsonPath('data.phone', '+998901112233')
        ->assertJsonPath('data.amount', 500000)
        ->assertJsonPath('data.can_update', true);

    $this->user->revokePermissionTo('Update:Debtor');

    $this->getJson('/mobile/api/v1/debtors/' . $this->debtor->id)
        ->assertOk()
        ->assertJsonPath('data.can_update', false);
});

it('updates debtor details and client info successfully', function (): void {
    $this->postJson('/mobile/api/v1/debtors/' . $this->debtor->id, $this->updatePayload)
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Yangilangan Qarzdor')
        ->assertJsonPath('data.phone', '+998909998877')
        ->assertJsonPath('data.amount', 450000)
        ->assertJsonPath('data.note', 'Yangilangan izoh');

    expect($this->debtor->fresh()->amount)->toBe(450000)
        ->and($this->debtor->fresh()->note)->toBe('Yangilangan izoh')
        ->and($this->client->fresh()->full_name)->toBe('Yangilangan Qarzdor')
        ->and($this->client->fresh()->phone)->toBe('+998909998877');
});

it('requires Update:Debtor permission to update debtor', function (): void {
    $this->user->revokePermissionTo('Update:Debtor');

    $this->postJson('/mobile/api/v1/debtors/' . $this->debtor->id, $this->updatePayload)
        ->assertForbidden();

    auth()->logout();

    $this->postJson('/mobile/api/v1/debtors/' . $this->debtor->id, $this->updatePayload)
        ->assertUnauthorized();
});

it('allows uploading and replacing debtor photo', function (): void {
    $photo = UploadedFile::fake()->image('yangi_qarzdor.png', 200, 200);

    $response = $this->post('/mobile/api/v1/debtors/' . $this->debtor->id, [
        ...$this->updatePayload,
        'photo' => $photo,
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $freshDebtor = $this->debtor->fresh();
    expect($freshDebtor->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($freshDebtor->photo_path);

    $oldPhotoPath = $freshDebtor->photo_path;

    // Replace with a new photo
    $newPhoto = UploadedFile::fake()->image('ikkinchi_rasm.png', 250, 250);
    $this->post('/mobile/api/v1/debtors/' . $this->debtor->id, [
        ...$this->updatePayload,
        'photo' => $newPhoto,
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $updatedDebtor = $this->debtor->fresh();
    expect($updatedDebtor->photo_path)->not->toBe($oldPhotoPath);
    Storage::disk('local')->assertMissing($oldPhotoPath);
    Storage::disk('local')->assertExists($updatedDebtor->photo_path);
});

it('allows removing debtor photo with remove_photo flag', function (): void {
    $photo = UploadedFile::fake()->image('yangi_qarzdor.png', 200, 200);
    $path  = app(DebtorPhotoService::class)->store($photo);
    $this->debtor->update(['photo_path' => $path]);
    Storage::disk('local')->assertExists($path);

    $this->postJson('/mobile/api/v1/debtors/' . $this->debtor->id, [
        ...$this->updatePayload,
        'remove_photo' => 1,
    ])->assertOk();

    expect($this->debtor->fresh()->photo_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('rejects update if phone belongs to another debtor with active debt in the same store', function (): void {
    $otherClient = Client::query()->create([
        'full_name' => 'Boshqa Mijoz',
        'phone'     => '+998905554433',
    ]);

    Debtor::query()->create([
        'store_id'  => $this->store->id,
        'client_id' => $otherClient->id,
        'amount'    => 200000,
        'currency'  => 'UZS',
        'date'      => today()->toDateString(),
    ]);

    $this->postJson('/mobile/api/v1/debtors/' . $this->debtor->id, [
        ...$this->updatePayload,
        'phone' => '+998905554433',
    ])->assertUnprocessable()->assertJsonValidationErrors('phone');
});

it('provides the earliest debt date as initial_debt_date when debtor has multiple transactions', function (): void {
    $this->debtor->update(['date' => '2026-10-15']);
    $this->debtor->transactions()->create([
        'type'   => 'debt',
        'amount' => 300000,
        'date'   => '2026-05-10 14:00:00',
        'note'   => 'Eng birinchi qarz',
    ]);
    $this->debtor->transactions()->create([
        'type'   => 'debt',
        'amount' => 200000,
        'date'   => '2026-08-20 10:00:00',
        'note'   => 'Ikkinchi qarz',
    ]);
    $this->debtor->transactions()->create([
        'type'   => 'payment',
        'amount' => 100000,
        'date'   => '2026-09-01 11:00:00',
        'note'   => 'Qisman tolov',
    ]);

    $this->getJson('/mobile/api/v1/debtors/' . $this->debtor->id)
        ->assertOk()
        ->assertJsonPath('data.initial_debt_date', '2026-05-10');

    expect($this->debtor->getInitialDebtDate())->toBe('2026-05-10');
});
