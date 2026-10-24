<?php

use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('flutter.enabled', true);

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Do‘kon B', 'address' => 'Samarqand', 'phone' => '+998902222222']);
    $this->storeC = Store::query()->create(['name' => 'Begona Do‘kon', 'address' => 'Buxoro', 'phone' => '+998903333333']);

    $this->user = User::factory()->create([
        'email'            => 'owner@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach([$this->storeA->id, $this->storeB->id]);

    $role        = Role::findOrCreate('admin', 'web');
    $permissions = ['view_pos', 'View:Pos', 'view_mobile_sales_statistics'];
    foreach ($permissions as $p) {
        $perm = Permission::findOrCreate($p, 'web');
        $role->givePermissionTo($perm);
        $this->user->givePermissionTo($perm);
    }

    $this->deviceUuid = (string) Str::uuid();
    $loginResponse    = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'owner@example.com',
        'password'    => 'password',
        'device_uuid' => $this->deviceUuid,
        'device_name' => 'iPhone 15',
        'platform'    => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('exposes sales cost visibility only to users with the matching permission', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/flutter/v1/session')
        ->assertOk()
        ->assertJsonPath('data.permissions.sales_cost_price', false);

    $permission = Permission::findOrCreate('view_mobile_sales_cost_price', 'web');
    $this->user->givePermissionTo($permission);

    $this->withToken($this->token)
        ->getJson('/api/flutter/v1/session')
        ->assertOk()
        ->assertJsonPath('data.permissions.sales_cost_price', true);
});

it('guarantees that requesting a store via flutter API never changes users current_store_id in database', function (): void {
    expect($this->user->fresh()->current_store_id)->toBe($this->storeA->id);

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeB->id}/session")
        ->assertOk()
        ->assertJsonPath('data.selected_store_id', (string) $this->storeB->id);

    expect($this->user->fresh()->current_store_id)->toBe($this->storeA->id);
});

it('keeps request store selection isolated across two concurrent devices belonging to the same user', function (): void {
    $deviceUuid2    = (string) Str::uuid();
    $loginResponse2 = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'owner@example.com',
        'password'    => 'password',
        'device_uuid' => $deviceUuid2,
        'device_name' => 'Samsung Galaxy S24',
        'platform'    => 'android',
    ])->assertCreated();

    $token2 = $loginResponse2->json('data.token');

    // Device 1 operates in Store A
    $resp1 = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/session")
        ->assertOk()
        ->json('data');

    // Device 2 operates concurrently in Store B
    $resp2 = $this->withToken($token2)
        ->getJson("/api/flutter/v1/stores/{$this->storeB->id}/session")
        ->assertOk()
        ->json('data');

    expect($resp1['selected_store_id'])->toBe((string) $this->storeA->id)
        ->and($resp2['selected_store_id'])->toBe((string) $this->storeB->id)
        ->and($resp1['device']['uuid'])->toBe($this->deviceUuid)
        ->and($resp2['device']['uuid'])->toBe($deviceUuid2);

    // Database user current_store_id remains unchanged
    expect($this->user->fresh()->current_store_id)->toBe($this->storeA->id);
});

it('rejects access to stores the user does not belong to with 403 store_forbidden', function (): void {
    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeC->id}/session")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'store_forbidden');
});

it('rejects invalid or malformed store IDs with 404 store_not_found', function (string $invalidStore): void {
    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$invalidStore}/session")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'store_not_found');
})->with(['0', '-5', 'abc', 'store1']);
