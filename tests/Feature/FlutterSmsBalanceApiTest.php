<?php

use App\Models\User;
use Livewire\Livewire;
use App\Services\SmsService;
use App\Models\FlutterDevice;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Http;
use App\Livewire\SmsBalanceIndicator;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['flutter.enabled' => true, 'sms.eskiz.email' => 'sms@example.test', 'sms.eskiz.password' => 'password']);
    Cache::flush();
    app()->instance(SmsService::class, new SmsService);
    Cache::put(app(SmsService::class)->tokenCacheKey(), 'test-token', now()->addDay());
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['balance' => 5000]])]);
    $this->user = User::factory()->create();
    $token      = $this->user->createToken('flutter:test', ['flutter:access'], now()->addDay());
    FlutterDevice::factory()->create(['user_id' => $this->user->id, 'personal_access_token_id' => $token->accessToken->id]);
    $this->token = $token->plainTextToken;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('requires a device token and dedicated permission for both balance routes', function (): void {
    $this->getJson('/api/flutter/v1/sms-balance')->assertUnauthorized();
    $this->postJson('/api/flutter/v1/sms-balance/refresh')->assertUnauthorized();
    $this->withToken($this->token)->getJson('/api/flutter/v1/sms-balance')->assertForbidden();
    $this->withToken($this->token)->postJson('/api/flutter/v1/sms-balance/refresh')->assertForbidden();
    Http::assertNothingSent();
});

it('exposes a shared balance and permission without revealing credentials', function (): void {
    $this->user->givePermissionTo(Permission::findOrCreate('view_sms_balance', 'web'));
    $this->withToken($this->token)->getJson('/api/flutter/v1/session')->assertOk()->assertJsonPath('data.permissions.sms_balance', true);
    $this->withToken($this->token)->getJson('/api/flutter/v1/sms-balance')->assertOk()
        ->assertJsonPath('data.balance', 5000)->assertJsonPath('data.status', 'low')->assertJsonPath('data.scope', 'account')
        ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.token');
    $this->withToken($this->token)->postJson('/api/flutter/v1/sms-balance/refresh')->assertOk();
    Http::assertSentCount(1);
});

it('allows super admins and keeps the feature flag on the balance routes', function (): void {
    $this->user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->withToken($this->token)->getJson('/api/flutter/v1/sms-balance')->assertOk();
    config(['flutter.enabled' => false]);
    $this->withToken($this->token)->getJson('/api/flutter/v1/sms-balance')->assertNotFound();
});

it('hides the admin indicator from users without permission', function (): void {
    $this->actingAs($this->user);
    expect(view('filament.sms-balance')->render())->not->toContain('sms-balance-indicator');
    Livewire::test(SmsBalanceIndicator::class)->assertForbidden();
    Http::assertNothingSent();
});

it('renders and refreshes the admin indicator with a low balance color', function (): void {
    $this->user->givePermissionTo(Permission::findOrCreate('view_sms_balance', 'web'));
    $this->actingAs($this->user);
    Livewire::test(SmsBalanceIndicator::class)->call('loadBalance')
        ->assertSet('balance.balance', 5000.0)->assertSee('5 000 so‘m')->assertSee('bg-amber-50', false)
        ->assertSee('Umumiy SMS balansi')->call('refreshBalance')->assertSet('balance.stale', false);
});

it('rechecks admin permission before refreshing a mounted indicator', function (): void {
    $this->user->givePermissionTo(Permission::findOrCreate('view_sms_balance', 'web'));
    $this->actingAs($this->user);
    $component = Livewire::test(SmsBalanceIndicator::class);
    $this->user->revokePermissionTo('view_sms_balance');
    $component->call('refreshBalance')->assertForbidden();
    Http::assertNothingSent();
});
