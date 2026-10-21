<?php

use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('flutter.enabled', true);
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Begona Do‘kon', 'address' => 'Buxoro', 'phone' => '+998902222222']);

    $this->user = User::factory()->create([
        'email'            => 'lease_user@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach($this->storeA->id);

    $this->deviceUuid = (string) Str::uuid();
    $loginResponse = $this->postJson('/api/flutter/v1/login', [
        'email'         => 'lease_user@example.com',
        'password'      => 'password',
        'device_uuid'   => $this->deviceUuid,
        'device_name'   => 'iPhone 15',
        'platform'      => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('issues an offline lease valid for maximum 10 hours', function (): void {
    $response = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/lease")
        ->assertOk()
        ->assertJsonPath('data.store_id', (string) $this->storeA->id)
        ->assertJsonPath('data.user_id', (string) $this->user->id)
        ->assertJsonPath('data.device_uuid', $this->deviceUuid)
        ->assertJsonPath('data.offline_max_seconds', 36000);

    $data = $response->json('data');
    expect(Str::isUuid($data['lease_id']))->toBeTrue();

    $issuedAt = Carbon::parse($data['issued_at']);
    $offlineUntil = Carbon::parse($data['offline_until']);
    expect((int) $issuedAt->diffInSeconds($offlineUntil))->toBe(36000);
});

it('forbids issuing a lease for an unassigned store', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeB->id}/lease")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'store_forbidden');
});
