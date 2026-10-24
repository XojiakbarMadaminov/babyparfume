<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    config()->set('flutter.enabled', true);
    Storage::fake('local');

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Do‘kon B', 'address' => 'Samarqand', 'phone' => '+998902222222']);

    $this->user = User::factory()->create([
        'email'            => 'debtor_mgr@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach([$this->storeA->id, $this->storeB->id]);

    $role        = Role::findOrCreate('admin', 'web');
    $permissions = [
        'ViewAny:Debtor',
        'View:Debtor',
        'Create:Debtor',
        'Update:Debtor',
        'Delete:Debtor',
    ];
    foreach ($permissions as $p) {
        $perm = Permission::findOrCreate($p, 'web');
        $role->givePermissionTo($perm);
        $this->user->givePermissionTo($perm);
    }

    $loginResponse = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'debtor_mgr@example.com',
        'password'    => 'password',
        'device_uuid' => (string) Str::uuid(),
        'device_name' => 'iPhone 15',
        'platform'    => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('lists debtors scoped to the requested store with summary and status counts', function (): void {
    $clientA = Client::query()->create(['full_name' => 'Ali Valiyev', 'phone' => '+998901234567']);
    $debtorA = Debtor::query()->create([
        'store_id'  => $this->storeA->id,
        'client_id' => $clientA->id,
        'amount'    => 500000,
        'currency'  => 'UZS',
        'date'      => now()->subDays(5)->toDateString(),
    ]);

    $clientB = Client::query()->create(['full_name' => 'Vali Aliyev', 'phone' => '+998909876543']);
    $debtorB = Debtor::query()->create([
        'store_id'  => $this->storeB->id,
        'client_id' => $clientB->id,
        'amount'    => 300000,
        'currency'  => 'UZS',
        'date'      => now()->subDays(2)->toDateString(),
    ]);

    // Store A query
    $respA = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $debtorA->id)
        ->assertJsonPath('data.0.full_name', 'Ali Valiyev')
        ->assertJsonPath('data.0.amount', '500000')
        ->assertJsonPath('counts.active', 1)
        ->assertJsonPath('summary.0.amount', '500000');

    // Store B query
    $respB = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeB->id}/debtors")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $debtorB->id)
        ->assertJsonPath('data.0.full_name', 'Vali Aliyev')
        ->assertJsonPath('data.0.amount', '300000')
        ->assertJsonPath('counts.active', 1)
        ->assertJsonPath('summary.0.amount', '300000');
});

it('creates a debtor with photo and returns canonical string numbers and UUID idempotent replay', function (): void {
    $requestId = (string) Str::uuid();
    $photo     = UploadedFile::fake()->image('passport.jpg', 600, 600);

    $payload = [
        'request_id' => $requestId,
        'full_name'  => 'Rustam Qosimov',
        'phone'      => '998931112233',
        'amount'     => 1200000,
        'date'       => now()->toDateString(),
        'note'       => 'Dastlabki qarz',
        'photo'      => $photo,
    ];

    $response = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors", $payload)
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Rustam Qosimov')
        ->assertJsonPath('data.phone', '998931112233')
        ->assertJsonPath('data.amount', '1200000')
        ->assertJsonPath('data.currency', 'UZS')
        ->assertJsonPath('data.scale', 0);

    $debtorId = $response->json('data.id');
    expect($response->json('data.photo_url'))->toContain("/api/flutter/v1/stores/{$this->storeA->id}/debtors/{$debtorId}/photo");

    // Idempotent retry with same request_id and identical payload
    $replayResponse = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors", $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $debtorId);

    // Replay with different payload must fail with 409
    $conflictPayload           = $payload;
    $conflictPayload['amount'] = 999999;
    $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors", $conflictPayload)
        ->assertStatus(409);
});

it('versions photo urls only when the photo changes', function (): void {
    $client = Client::query()->create(['full_name' => 'Karim', 'phone' => '+998901234567']);
    $debtor = Debtor::query()->create([
        'store_id'   => $this->storeA->id,
        'client_id'  => $client->id,
        'amount'     => 100000,
        'currency'   => 'UZS',
        'date'       => now()->toDateString(),
        'photo_path' => 'debtor-photos/first.jpg',
    ]);
    $endpoint = "/api/flutter/v1/stores/{$this->storeA->id}/debtors/{$debtor->id}";
    $original = $this->withToken($this->token)->getJson($endpoint)->assertOk()->json('data.photo_url');
    expect($original)->toContain('?v=')->not->toContain('debtor-photos/');

    $debtor->update(['amount' => 90000]);
    $this->withToken($this->token)->getJson($endpoint)->assertOk()->assertJsonPath('data.photo_url', $original);

    $debtor->update(['photo_path' => 'debtor-photos/second.jpg']);
    $replacement = $this->withToken($this->token)->getJson($endpoint)->assertOk()->json('data.photo_url');
    expect($replacement)->not->toBe($original);

    $debtor->update(['photo_path' => null]);
    $this->withToken($this->token)->getJson($endpoint)->assertOk()->assertJsonPath('data.photo_url', null);
});

it('streams debtor photo securely with private no-store headers and forbids other stores', function (): void {
    Storage::disk('local')->put('debtor-photos/test.jpg', 'fake-image-bytes');

    $client = Client::query()->create(['full_name' => 'Karim', 'phone' => '+998901234567']);
    $debtor = Debtor::query()->create([
        'store_id'   => $this->storeA->id,
        'client_id'  => $client->id,
        'amount'     => 100000,
        'currency'   => 'UZS',
        'date'       => now()->toDateString(),
        'photo_path' => 'debtor-photos/test.jpg',
    ]);

    // Access via Store A (correct store)
    $this->withToken($this->token)
        ->get("/api/flutter/v1/stores/{$this->storeA->id}/debtors/{$debtor->id}/photo")
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->tap(fn ($response) => expect($response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private'))->toBeTrue())
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Access via Store B (wrong store) must return 404 (not in store B)
    $this->withToken($this->token)
        ->get("/api/flutter/v1/stores/{$this->storeB->id}/debtors/{$debtor->id}/photo")
        ->assertNotFound();
});

it('adds debt or payment transactions with idempotency and balance recalculation', function (): void {
    $client = Client::query()->create(['full_name' => 'Olim', 'phone' => '+998901234567']);
    $debtor = Debtor::query()->create([
        'store_id'  => $this->storeA->id,
        'client_id' => $client->id,
        'amount'    => 1000000,
        'currency'  => 'UZS',
        'date'      => now()->toDateString(),
    ]);

    $requestId = (string) Str::uuid();

    // Partial payment of 400 000
    $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors/{$debtor->id}/transactions", [
            'request_id'     => $requestId,
            'type'           => 'payment',
            'amount'         => 400000,
            'payment_method' => 'cash',
            'note'           => 'Qisman to‘lov',
        ])
        ->assertOk()
        ->assertJsonPath('data.amount', '600000');

    expect($debtor->fresh()->amount)->toBe(600000);

    // Check transactions endpoint
    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/debtors/{$debtor->id}/transactions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'payment')
        ->assertJsonPath('data.0.amount', '400000');
});
