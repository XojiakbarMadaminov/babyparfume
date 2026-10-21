<?php

use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Str;
use App\Models\FlutterDevice;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use App\Services\FlutterDeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['flutter.enabled' => true]);
});

function flutterLoginPayload(User $user, ?string $deviceUuid = null): array
{
    return [
        'email'       => $user->email,
        'password'    => 'password',
        'device_uuid' => $deviceUuid ?? (string) Str::uuid(),
        'device_name' => 'Test Android',
        'platform'    => 'android',
    ];
}

dataset('flutter authentication routes', [
    ['GET', '/health'],
    ['POST', '/login'],
    ['GET', '/session'],
    ['POST', '/logout'],
    ['GET', '/stores/1/session'],
    ['DELETE', '/devices/71503d44-a786-431c-b8fb-4f0e3f78fbcd'],
]);

it('keeps every new endpoint disabled by default', function (string $method, string $path): void {
    config(['flutter.enabled' => false]);

    $this->json($method, '/api/flutter/v1' . $path)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'feature_disabled');

    expect(FlutterDevice::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
})->with('flutter authentication routes');

it('publishes capabilities without claiming that offline sync is implemented', function (): void {
    $response = $this->getJson('/api/flutter/v1/health')
        ->assertOk()
        ->assertJsonPath('data.protocol_version', 1)
        ->assertJsonPath('data.authentication', 'bearer')
        ->assertJsonPath('data.offline_max_seconds', 36000)
        ->assertJsonPath('data.features.device_sessions', true)
        ->assertJsonPath('data.features.offline_lease', true)
        ->assertJsonPath('data.features.sync', true);

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
});

it('registers a hashed device token without changing web authentication or selected store', function (): void {
    $user    = User::factory()->create();
    $payload = flutterLoginPayload($user);

    $response = $this->postJson('/api/flutter/v1/login', $payload)
        ->assertCreated()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.device_uuid', $payload['device_uuid'])
        ->assertJsonPath('data.offline_ready', false);
    $token = $response->json('data.token');

    expect(PersonalAccessToken::findToken($token))->not->toBeNull()
        ->and(PersonalAccessToken::query()->first()->token)->not->toBe($token)
        ->and($user->fresh()->current_store_id)->toBeNull()
        ->and(Auth::guard('web')->check())->toBeFalse();

    $this->withToken($token)->getJson('/api/flutter/v1/session')
        ->assertOk()
        ->assertJsonPath('data.user.id', (string) $user->id)
        ->assertJsonPath('data.selected_store_id', null)
        ->assertJsonMissingPath('data.user.password')
        ->assertJsonMissingPath('data.user.remember_token')
        ->assertJsonMissingPath('data.token');
});

it('requires bearer authentication even when a valid web user is present', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->getJson('/api/flutter/v1/session')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->assertAuthenticatedAs($user, 'web');
});

it('uses the bearer account instead of an unrelated logged in web account', function (): void {
    $mobileUser = User::factory()->create();
    $webUser    = User::factory()->create();
    $token      = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($mobileUser))->json('data.token');

    $this->actingAs($webUser, 'web')->withToken($token)->getJson('/api/flutter/v1/session')
        ->assertOk()->assertJsonPath('data.user.id', (string) $mobileUser->id);

    $this->assertAuthenticatedAs($webUser, 'web');
    expect(Auth::getDefaultDriver())->toBe('web');
});

it('rejects invalid credentials without creating a device or token', function (): void {
    $user                = User::factory()->create();
    $payload             = flutterLoginPayload($user);
    $payload['password'] = 'incorrect';

    $this->postJson('/api/flutter/v1/login', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(FlutterDevice::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('validates device registration before creating credentials', function (string $field, mixed $value): void {
    $payload         = flutterLoginPayload(User::factory()->create());
    $payload[$field] = $value;

    $this->postJson('/api/flutter/v1/login', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(FlutterDevice::query()->count())->toBe(0);
})->with([
    ['device_uuid', 'not-a-uuid'],
    ['device_name', ''],
    ['device_name', str_repeat('x', 101)],
    ['platform', 'web'],
    ['password', ''],
    ['email', 'invalid'],
    ['email', ['invalid']],
]);

it('rotates only the same device token on repeated login', function (): void {
    $user       = User::factory()->create();
    $payload    = flutterLoginPayload($user);
    $oldToken   = $this->postJson('/api/flutter/v1/login', $payload)->json('data.token');
    $otherToken = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');
    $newToken   = $this->postJson('/api/flutter/v1/login', $payload)->json('data.token');

    expect(FlutterDevice::query()->count())->toBe(2)
        ->and(PersonalAccessToken::findToken($oldToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($newToken))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($otherToken))->not->toBeNull();

    $this->withToken($oldToken)->getJson('/api/flutter/v1/session')->assertUnauthorized();
    $this->withToken($newToken)->getJson('/api/flutter/v1/session')->assertOk();
});

it('rejects expired tokens', function (): void {
    config(['flutter.token_lifetime_minutes' => 1]);
    $token = $this->postJson('/api/flutter/v1/login', flutterLoginPayload(User::factory()->create()))->json('data.token');
    $this->travel(2)->minutes();

    $this->withToken($token)->getJson('/api/flutter/v1/session')->assertUnauthorized();
});

it('rejects tokens not registered to an active flutter device', function (): void {
    $user  = User::factory()->create();
    $token = $user->createToken('unregistered', ['flutter:access'])->plainTextToken;

    $this->withToken($token)->getJson('/api/flutter/v1/session')
        ->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
});

it('rejects registered tokens without flutter ability', function (): void {
    $user  = User::factory()->create();
    $token = $user->createToken('other-app', ['other:access']);
    FlutterDevice::factory()->for($user)->create(['personal_access_token_id' => $token->accessToken->id]);

    $this->withToken($token->plainTextToken)->getJson('/api/flutter/v1/session')->assertUnauthorized();
});

it('does not reuse a previous bearer user on the next unauthenticated request', function (): void {
    $token = $this->postJson('/api/flutter/v1/login', flutterLoginPayload(User::factory()->create()))->json('data.token');
    $this->withToken($token)->getJson('/api/flutter/v1/session')->assertOk();
    $this->withoutToken()->getJson('/api/flutter/v1/session')->assertUnauthorized();
});

it('does not revoke a newly rotated token when an older logout finishes later', function (): void {
    $payload    = flutterLoginPayload(User::factory()->create());
    $oldToken   = $this->postJson('/api/flutter/v1/login', $payload)->json('data.token');
    $oldDevice  = FlutterDevice::query()->firstOrFail();
    $oldTokenId = PersonalAccessToken::findToken($oldToken)->id;
    $newToken   = $this->postJson('/api/flutter/v1/login', $payload)->json('data.token');

    app(FlutterDeviceSessionService::class)->revoke($oldDevice, $oldTokenId);

    $this->withToken($newToken)->getJson('/api/flutter/v1/session')->assertOk();
});

it('keeps request store selection independent across phones and the web user', function (): void {
    $firstStore  = Store::query()->create(['name' => 'Birinchi', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $secondStore = Store::query()->create(['name' => 'Ikkinchi', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $user        = User::factory()->create(['current_store_id' => $firstStore->id]);
    $user->stores()->attach([$firstStore->id, $secondStore->id]);
    $firstToken  = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');
    $secondToken = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');

    $this->withToken($firstToken)->getJson('/api/flutter/v1/stores/' . $firstStore->id . '/session')
        ->assertOk()->assertJsonPath('data.selected_store_id', (string) $firstStore->id);
    $this->withToken($secondToken)->getJson('/api/flutter/v1/stores/' . $secondStore->id . '/session')
        ->assertOk()->assertJsonPath('data.selected_store_id', (string) $secondStore->id);
    $this->withToken($firstToken)->getJson('/api/flutter/v1/session')->assertOk()->assertJsonPath('data.selected_store_id', null);

    expect($user->fresh()->current_store_id)->toBe($firstStore->id);
    $user->stores()->detach($secondStore);
    $this->withToken($secondToken)->getJson('/api/flutter/v1/stores/' . $secondStore->id . '/session')->assertForbidden();
});

it('does not reveal deleted or unassigned stores', function (bool $deleted): void {
    $store = Store::query()->create(['name' => 'Begona', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create();
    if ($deleted) {
        $user->stores()->attach($store);
        $store->delete();
    }
    $token = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');

    $this->withToken($token)->getJson('/api/flutter/v1/stores/' . $store->id . '/session')->assertForbidden();
    $this->getJson('/api/flutter/v1/session')->assertOk()->assertJsonCount(0, 'data.stores');
})->with([false, true]);

it('logs out one phone without logging out the web account or another phone', function (): void {
    $user        = User::factory()->create();
    $firstToken  = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');
    $secondToken = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');

    $this->actingAs($user, 'web')->withSession(['pos_carts' => ['desktop-marker'], 'mobile_sales' => ['mobile-marker']]);
    $this->withToken($firstToken)->postJson('/api/flutter/v1/logout')->assertNoContent();
    $this->assertAuthenticatedAs($user, 'web');
    expect(session('pos_carts'))->toBe(['desktop-marker'])
        ->and(session('mobile_sales'))->toBe(['mobile-marker']);

    $this->withToken($firstToken)->getJson('/api/flutter/v1/session')->assertUnauthorized();
    $this->withToken($secondToken)->getJson('/api/flutter/v1/session')->assertOk();
});

it('allows revoking another owned device but not another account device', function (): void {
    $user          = User::factory()->create();
    $firstToken    = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($user))->json('data.token');
    $secondPayload = flutterLoginPayload($user);
    $secondToken   = $this->postJson('/api/flutter/v1/login', $secondPayload)->json('data.token');
    $foreignDevice = FlutterDevice::factory()->create();

    $this->withToken($firstToken)->deleteJson('/api/flutter/v1/devices/' . $foreignDevice->device_uuid)->assertNotFound();
    $this->deleteJson('/api/flutter/v1/devices/' . $secondPayload['device_uuid'])->assertNoContent();
    $this->withToken($secondToken)->getJson('/api/flutter/v1/session')->assertUnauthorized();
    $this->withToken($firstToken)->getJson('/api/flutter/v1/session')->assertOk();
});

it('throttles login attempts without depending on a web session', function (): void {
    $payload             = flutterLoginPayload(User::factory()->create());
    $payload['password'] = 'incorrect';
    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/flutter/v1/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/flutter/v1/login', $payload)->assertTooManyRequests()->assertHeader('Retry-After');
});

it('does not bind a copied installation uuid to a different account', function (): void {
    $firstUser   = User::factory()->create();
    $secondUser  = User::factory()->create();
    $deviceUuid  = (string) Str::uuid();
    $firstToken  = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($firstUser, $deviceUuid))->json('data.token');
    $secondToken = $this->postJson('/api/flutter/v1/login', flutterLoginPayload($secondUser, $deviceUuid))->json('data.token');

    expect(FlutterDevice::query()->count())->toBe(2);
    $this->withToken($firstToken)->getJson('/api/flutter/v1/session')->assertOk()->assertJsonPath('data.user.id', (string) $firstUser->id);
    $this->withToken($secondToken)->getJson('/api/flutter/v1/session')->assertOk()->assertJsonPath('data.user.id', (string) $secondUser->id);
});

it('rejects a device after administrative revocation even if its token remains', function (): void {
    $token = $this->postJson('/api/flutter/v1/login', flutterLoginPayload(User::factory()->create()))->json('data.token');
    $this->withToken($token)->getJson('/api/flutter/v1/session')->assertOk();
    FlutterDevice::query()->update(['revoked_at' => now()]);

    $this->getJson('/api/flutter/v1/session')->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
});

it('rejects malformed store ids without a database error', function (string $store): void {
    $token = $this->postJson('/api/flutter/v1/login', flutterLoginPayload(User::factory()->create()))->json('data.token');

    $this->withToken($token)->getJson('/api/flutter/v1/stores/' . $store . '/session')->assertNotFound();
})->with(['0', '-1', 'abc', '999999999999999999999999999999']);

it('prevents caching and session cookies on both successful and rejected requests', function (): void {
    $success         = $this->get('/api/flutter/v1/health')->assertOk()->assertHeaderMissing('Set-Cookie');
    $unauthenticated = $this->get('/api/flutter/v1/session')->assertUnauthorized()->assertHeaderMissing('Set-Cookie');
    $invalid         = $this->post('/api/flutter/v1/login', [])->assertUnprocessable()->assertHeaderMissing('Set-Cookie');
    config(['flutter.enabled' => false]);
    $disabled = $this->get('/api/flutter/v1/health')->assertNotFound()->assertHeaderMissing('Set-Cookie');

    foreach ([$success, $unauthenticated, $invalid, $disabled] as $response) {
        expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
            ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue();
    }
});

it('returns json for unknown flutter routes without requiring an accept header', function (): void {
    $response = $this->get('/api/flutter/v1/not-implemented')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
});
