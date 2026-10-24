<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use Livewire\Livewire;
use Illuminate\Support\Str;
use App\Livewire\Mobile\Sales;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['flutter.enabled' => true]);
    $this->store = Store::query()->create(['name' => 'Telefon test', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (['View:Pos', 'Create:Debtor', 'ViewAny:Debtor', 'View:Debtor'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');
});

dataset('editable customer phones', [
    'default prefix'       => ['+998901234569', '+998901234569'],
    'formatted prefix'     => ['+998 (90) 123-45-69', '+998901234569'],
    'changed country code' => ['+7 (903) 123-45-67', '+79031234567'],
    'removed prefix'       => ['901234569', '901234569'],
    'removed plus'         => ['998901234569', '998901234569'],
]);

function phoneTestFlutterToken($test): string
{
    return $test->postJson('/api/flutter/v1/login', [
        'email'       => $test->user->email, 'password' => 'password',
        'device_uuid' => (string) Str::uuid(), 'device_name' => 'Phone test', 'platform' => 'android',
    ])->assertCreated()->json('data.token');
}

it('prefills the editable mobile customer phone and stores the submitted prefix', function (string $input, string $expected): void {
    Livewire::test(Sales::class)->call('toggleCreateClientForm')
        ->assertSet('newClient.phone', '+998')
        ->set('newClient.full_name', 'Telefon Mijozi')->set('newClient.phone', $input)
        ->call('createClient')->assertHasNoErrors()
        ->assertSet('newClient.phone', '+998');

    expect(Client::query()->sole()->phone)->toBe($expected);
})->with('editable customer phones');

it('preserves customer phone edits through mobile and flutter debtor routes', function (string $channel, string $input, string $expected): void {
    $path = '/mobile/api/v1/debtors';
    if ($channel === 'flutter') {
        $this->withToken(phoneTestFlutterToken($this));
        $path = '/api/flutter/v1/stores/' . $this->store->id . '/debtors';
    }

    $this->postJson($path, [
        'request_id' => (string) Str::uuid(), 'store_id' => $this->store->id,
        'full_name'  => 'Telefon Mijozi', 'phone' => $input, 'amount' => 10000, 'date' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.phone', $expected);

    expect(Client::query()->sole()->phone)->toBe($expected);
})->with(['mobile', 'flutter'])->with('editable customer phones');

it('preserves the edited phone when syncing an offline customer or debtor', function (string $entity, string $input, string $expected): void {
    $token   = phoneTestFlutterToken($this);
    $payload = ['full_name' => 'Offline Mijoz', 'phone' => $input];
    if ($entity === 'debtor') {
        $payload += ['amount' => '10000', 'date' => today()->toDateString()];
    }

    $this->withToken($token)->postJson('/api/flutter/v1/stores/' . $this->store->id . '/operations', [
        'operations' => [[
            'operation_id'   => (string) Str::uuid(), 'entity_type' => $entity,
            'operation_type' => $entity . '.create', 'local_id' => (string) Str::uuid(), 'payload' => $payload,
        ]],
    ])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

    expect(Client::query()->sole()->phone)->toBe($expected);
})->with(['client', 'debtor'])->with('editable customer phones');

it('rejects saving only the default phone prefix', function (): void {
    Livewire::test(Sales::class)->call('toggleCreateClientForm')
        ->set('newClient.full_name', 'Telefon Mijozi')->call('createClient')
        ->assertHasErrors(['newClient.phone']);

    expect(Client::query()->count())->toBe(0);
});

it('recognizes the same customer after the phone prefix was removed', function (): void {
    Client::factory()->create(['phone' => '+998901234569']);
    Livewire::test(Sales::class)->call('toggleCreateClientForm')
        ->set('newClient.full_name', 'Telefon Mijozi')
        ->set('newClient.phone', '901234569')->call('createClient')
        ->assertHasErrors(['newClient.phone']);

    expect(Client::query()->count())->toBe(1);
});
