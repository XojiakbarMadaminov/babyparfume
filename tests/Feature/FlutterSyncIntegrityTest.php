<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Support\Str;
use App\Services\SmsService;
use App\Services\TelegramNotifier;
use App\Models\FlutterSyncOperation;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['flutter.enabled' => true]);
    Illuminate\Support\Facades\Storage::fake('local');
    $this->mock(App\Services\TelegramSaleNotifier::class)->shouldReceive('notify');
    $this->mock(SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    $this->store      = Store::query()->create(['name' => 'Audit', 'address' => 'Toshkent', 'phone' => '+998901234567']);
    $this->otherStore = Store::query()->create(['name' => 'Boshqa', 'address' => 'Toshkent', 'phone' => '+998901234568']);
    $this->stock      = Stock::query()->create(['name' => 'Audit ombor', 'is_active' => true]);
    $this->stock->stores()->attach($this->store);
    $this->user = User::factory()->create(['current_store_id' => $this->otherStore->id]);
    $this->user->stores()->attach([$this->store->id, $this->otherStore->id]);
    foreach (['View:Pos', 'ViewAny:Product', 'View:Product', 'ViewAny:Debtor', 'View:Debtor', 'Create:Debtor', 'Update:Debtor'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->token = $this->postJson('/api/flutter/v1/login', [
        'email'       => $this->user->email, 'password' => 'password', 'device_uuid' => (string) Str::uuid(),
        'device_name' => 'Audit', 'platform' => 'android',
    ])->assertCreated()->json('data.token');
    $this->prefix  = '/api/flutter/v1/stores/' . $this->store->id;
    $this->product = Product::factory()->create(['store_id' => $this->store->id, 'type' => 'package', 'price' => 15000, 'initial_price' => 10000]);
    $this->product->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 1]);
});

function integritySaleEnvelope($test, array $overrides = []): array
{
    return [
        'operation_id' => (string) Str::uuid(), 'entity_type' => 'sale', 'operation_type' => 'sale.create',
        'payload'      => array_replace_recursive([
            'local_sale_id' => (string) Str::uuid(),
            'items'         => [['product_id' => (string) $test->product->id, 'stock_id' => (string) $test->stock->id, 'quantity' => 2, 'unit_price' => '15000']],
            'payment'       => ['type' => 'cash', 'cash' => '30000', 'card' => '0'],
        ], $overrides),
    ];
}

it('rolls back the sale stock and operation together when acknowledgement persistence fails', function (): void {
    $envelope = integritySaleEnvelope($this);
    $fail     = true;
    Event::listen('eloquent.updated: ' . FlutterSyncOperation::class, function () use (&$fail): void {
        if ($fail) {
            $fail = false;

            throw new RuntimeException('ack failed');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]]))->toThrow(RuntimeException::class);
    expect(Sale::withoutGlobalScopes()->count())->toBe(0)
        ->and(FlutterSyncOperation::query()->count())->toBe(0)
        ->and($this->product->productStocks()->first()->quantity)->toBe(1);
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    expect(Sale::withoutGlobalScopes()->count())->toBe(1);
});

it('does not disclose or replay an operation through a different store or device', function (): void {
    $envelope = integritySaleEnvelope($this);
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertJsonPath('data.results.0.status', 'applied');
    $this->withToken($this->token)->postJson('/api/flutter/v1/stores/' . $this->otherStore->id . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.error', 'idempotency_mismatch')->assertJsonMissingPath('data.results.0.response_data');
    $otherToken = $this->postJson('/api/flutter/v1/login', ['email' => $this->user->email, 'password' => 'password',
        'device_uuid'                                               => (string) Str::uuid(), 'device_name' => 'B', 'platform' => 'ios'])->json('data.token');
    $this->withToken($otherToken)->getJson($this->prefix . '/operations/' . $envelope['operation_id'])->assertNotFound();
    $this->withToken($otherToken)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertJsonPath('data.results.0.error', 'idempotency_mismatch');
    expect(Sale::withoutGlobalScopes()->count())->toBe(1);
});

it('validates envelopes before accepting any operation', function (): void {
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [
        ['operation_id' => 'bad', 'entity_type' => 'sale', 'operation_type' => 'sale.create', 'payload' => []],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['operations.0.operation_id']);
    expect(FlutterSyncOperation::query()->count())->toBe(0);
});

it('rejects negative quantity and foreign warehouse without posting any money', function (string $invalid): void {
    $envelope = integritySaleEnvelope($this);
    if ($invalid === 'quantity') {
        $envelope['payload']['items'][0]['quantity'] = -2;
    } else {
        $foreignStock = Stock::query()->create(['name' => 'Begona', 'is_active' => true]);
        $foreignStock->stores()->attach($this->otherStore);
        $envelope['payload']['items'][0]['stock_id'] = (string) $foreignStock->id;
    }
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'conflict');
    expect(Sale::withoutGlobalScopes()->count())->toBe(0);
})->with(['quantity', 'stock']);

it('creates and maps an offline debtor before applying its payment exactly once', function (): void {
    $create = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'debtor', 'operation_type' => 'debtor.create',
        'local_id'            => (string) Str::uuid(), 'payload' => ['full_name' => 'Ali Audit', 'phone' => '901234567', 'amount' => '50000']];
    $created = $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$create]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied')->json('data.results.0');
    $payment = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'debtor', 'operation_type' => 'debtor.transact',
        'entity_id'            => $create['local_id'], 'depends_on' => [$create['operation_id']], 'base_operation_id' => $create['operation_id'],
        'payload'              => ['type' => 'payment', 'amount' => '10000', 'payment_method' => 'cash']];
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$payment]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$payment]])
        ->assertJsonPath('data.results.0.is_replay', true);
    $debtor = Debtor::withoutGlobalScopes()->findOrFail($created['canonical_id']);
    expect((int) $debtor->amount)->toBe(40000)->and($debtor->transactions()->count())->toBe(2)
        ->and($this->user->fresh()->current_store_id)->toBe($this->otherStore->id);
});

it('returns the original snapshot and permission safe canonical delta after product changes', function (): void {
    $originalName = $this->product->name;
    $snapshot     = $this->withToken($this->token)->postJson($this->prefix . '/bootstrap')->assertOk()->json('data.snapshot_id');
    $this->product->update(['name' => 'Keyin o‘zgardi']);
    $this->withToken($this->token)->getJson($this->prefix . '/bootstrap/' . $snapshot . '?entity=products')
        ->assertOk()->assertJsonPath('data.items.0.name', $originalName)->assertJsonMissingPath('data.items.0.initial_price');
    $delta = $this->withToken($this->token)->getJson($this->prefix . '/delta')->assertOk();
    expect(collect($delta->json('data.changes'))->where('entity', 'product')->last()['value']['name'])->toBe('Keyin o‘zgardi');
    foreach ($delta->json('data.changes') as $change) {
        expect($change['value'])->not->toHaveKey('initial_price');
    }
    $otherToken = $this->postJson('/api/flutter/v1/login', ['email' => $this->user->email, 'password' => 'password',
        'device_uuid'                                               => (string) Str::uuid(), 'device_name' => 'B', 'platform' => 'ios'])->json('data.token');
    $this->withToken($otherToken)->getJson($this->prefix . '/bootstrap/' . $snapshot . '?entity=products')->assertNotFound();
});

it('requires an explicit admin permission and resolves a stale payment only once', function (): void {
    config(['flutter.conflicts_enabled' => true]);
    $client = Client::factory()->create();
    $debtor = Debtor::withoutEvents(fn () => Debtor::query()->create(['store_id' => $this->store->id,
        'client_id'                                                              => $client->id, 'amount' => 50000, 'currency' => 'UZS', 'date' => now()->toDateString()]));
    $envelope = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'debtor', 'operation_type' => 'debtor.transact',
        'entity_id'             => (string) $debtor->id, 'base_revision' => 99, 'payload' => ['type' => 'payment', 'amount' => '10000']];
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'conflict');
    $conflict = App\Models\FlutterSyncConflict::query()->firstOrFail();
    $url      = $this->prefix . '/conflicts/' . $conflict->id . '/resolve';
    $this->withToken($this->token)->postJson($url, ['decision' => 'apply', 'note' => 'Pul qabul qilingani tekshirildi.'])->assertForbidden();
    $this->user->givePermissionTo(Permission::findOrCreate('resolve_flutter_sync_conflicts', 'web'));
    $this->withToken($this->token)->postJson($url, ['decision' => 'apply', 'note' => 'Pul qabul qilingani tekshirildi.'])
        ->assertOk()->assertJsonPath('data.status', 'applied');
    $this->withToken($this->token)->postJson($url, ['decision' => 'apply', 'note' => 'Qayta urinish.'])
        ->assertOk()->assertJsonPath('data.is_replay', true);
    $this->withToken($this->token)->postJson($url, ['decision' => 'reject', 'note' => 'Boshqa qaror.'])->assertConflict();
    expect((int) $debtor->fresh()->amount)->toBe(40000)->and($debtor->transactions()->count())->toBe(1)
        ->and($conflict->fresh()->resolved_by)->toBe($this->user->id);
});

it('does not post a second sale for the same local receipt under a new operation UUID', function (): void {
    $envelope = integritySaleEnvelope($this);
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertJsonPath('data.results.0.status', 'applied');
    $envelope['operation_id'] = (string) Str::uuid();
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertJsonPath('data.results.0.status', 'conflict');
    expect(Sale::withoutGlobalScopes()->count())->toBe(1);
});

it('syncs a debtor photo privately and replays it without creating another file', function (): void {
    $client = Client::factory()->create();
    $debtor = Debtor::withoutEvents(fn () => Debtor::query()->create(['store_id' => $this->store->id,
        'client_id'                                                              => $client->id, 'amount' => 50000, 'currency' => 'UZS', 'date' => now()->toDateString()]));
    $photo    = Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 40, 40);
    $envelope = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'debtor', 'operation_type' => 'debtor.photo',
        'entity_id'             => (string) $debtor->id, 'base_revision' => app(App\Services\FlutterSyncService::class)->entityRevision($this->store->id, 'debtor', $debtor->id),
        'payload'               => ['action' => 'replace', 'image_base64' => base64_encode(file_get_contents($photo->getRealPath()))]];
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertJsonPath('data.results.0.is_replay', true);
    expect(Illuminate\Support\Facades\Storage::disk('local')->allFiles('debtor-photos'))->toHaveCount(1);
    $photoResponse = $this->withToken($this->token)->get($this->prefix . '/debtors/' . $debtor->id . '/photo')->assertOk();
    expect($photoResponse->headers->hasCacheControlDirective('no-store') && $photoResponse->headers->hasCacheControlDirective('private'))->toBeTrue();
});

it('captures bulk web and import changes including stock and soft delete tombstones', function (): void {
    $sync      = app(App\Services\FlutterSyncService::class);
    $watermark = $sync->getLatestWatermark($this->store->id);
    $this->product->productStocks()->update(['quantity' => 0]);
    Product::withoutGlobalScopes()->whereKey($this->product->id)->update(['name' => 'Import nomi']);
    $delta = $this->withToken($this->token)->getJson($this->prefix . '/delta?cursor=' . $watermark)->assertOk();
    expect($delta->json('data.changes'))->not->toBeEmpty();
    expect(collect($delta->json('data.changes'))->last()['value']['quantity'])->toBe(0)
        ->and(collect($delta->json('data.changes'))->last()['value']['name'])->toBe('Import nomi');
    $watermark = $sync->getLatestWatermark($this->store->id);
    Product::withoutGlobalScopes()->whereKey($this->product->id)->delete();
    $this->withToken($this->token)->getJson($this->prefix . '/delta?cursor=' . $watermark)
        ->assertOk()->assertJsonPath('data.changes.0.action', 'delete')->assertJsonPath('data.changes.0.value', null);
});

it('preserves the cached cost revision and allocates receipt discounts into sale items', function (): void {
    $cachedRevision = app(App\Services\FlutterSyncService::class)->entityRevision($this->store->id, 'product', $this->product->id);
    $this->product->update(['initial_price' => 14000, 'price' => 20000]);
    $envelope                                            = integritySaleEnvelope($this, ['receipt_discount' => '3000']);
    $envelope['payload']['items'][0]['product_revision'] = $cachedRevision;
    $response                                            = $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied')
        ->assertJsonPath('data.results.0.response_data.total_amount', '27000.00');
    $sale = Sale::withoutGlobalScopes()->findOrFail($response->json('data.results.0.canonical_id'));
    expect($sale->items()->first()->unit_cost)->toBe('10000.00')
        ->and((float) $sale->items()->sum('total'))->toBe(27000.0)
        ->and((float) $sale->items()->first()->price)->toBe(13500.0);
});

it('returns stock and statistics for the token store while a web guard uses another store', function (): void {
    $this->user->givePermissionTo(Permission::findOrCreate('view_mobile_sales_statistics', 'web'));
    $this->actingAs($this->user, 'web');
    $this->withToken($this->token)->getJson($this->prefix . '/products')->assertOk()->assertJsonPath('data.0.quantity', 1);
    $envelope = integritySaleEnvelope($this);
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    $date = now()->toDateString();
    $this->withToken($this->token)->getJson($this->prefix . '/statistics?period=today&start=' . $date . '&end=' . $date)
        ->assertOk()->assertJsonPath('data.sales_count', 1)->assertJsonPath('data.sales_amount', '30000')
        ->assertJsonPath('data.profit', '10000');
    expect($this->user->fresh()->current_store_id)->toBe($this->otherStore->id);
});

it('maps an offline new client through a credit sale and dependent payment', function (): void {
    $clientId = (string) Str::uuid();
    $debtorId = (string) Str::uuid();
    $client   = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'client', 'operation_type' => 'client.create',
        'local_id'              => $clientId, 'payload' => ['full_name' => 'Yangi Xaridor', 'phone' => '+998901234569']];
    $sale = integritySaleEnvelope($this, ['client' => ['local_id' => $clientId], 'local_debtor_id' => $debtorId,
        'payment'                                  => ['type' => 'debt', 'cash' => '0', 'card' => '0']]);
    $sale['depends_on'] = [$client['operation_id']];
    $payment            = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'debtor', 'operation_type' => 'debtor.transact',
        'entity_id'                       => $debtorId, 'depends_on' => [$sale['operation_id']], 'base_operation_id' => $sale['operation_id'],
        'payload'                         => ['type' => 'payment', 'amount' => '10000']];
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$client, $sale, $payment]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied')->assertJsonPath('data.results.1.status', 'applied')
        ->assertJsonPath('data.results.2.status', 'applied');
    expect(Client::query()->count())->toBe(1)->and(Sale::withoutGlobalScopes()->count())->toBe(1)
        ->and((int) Debtor::withoutGlobalScopes()->first()->amount)->toBe(20000);
});

it('requires administrator approval before linking a duplicate offline client', function (): void {
    config(['flutter.conflicts_enabled' => true]);
    $existing  = Client::factory()->create(['phone' => '+998901234569']);
    $operation = ['operation_id' => (string) Str::uuid(), 'entity_type' => 'client', 'operation_type' => 'client.create',
        'local_id'               => (string) Str::uuid(), 'payload' => ['full_name' => 'Boshqa yozilish', 'phone' => '901234569']];
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$operation]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'conflict');
    $this->user->givePermissionTo(Permission::findOrCreate('resolve_flutter_sync_conflicts', 'web'));
    $conflict = App\Models\FlutterSyncConflict::query()->firstOrFail();
    $this->withToken($this->token)->postJson(
        $this->prefix . '/conflicts/' . $conflict->id . '/resolve',
        ['decision' => 'apply', 'note' => 'Telefon va mijoz tekshirilib bog‘landi.']
    )
        ->assertOk()->assertJsonPath('data.canonical_id', (string) $existing->id);
    expect(Client::query()->count())->toBe(1)->and($existing->fresh()->full_name)->toBe($existing->full_name);
});

it('captures global client bulk edits and restores and can reinstall change capture triggers', function (): void {
    $migration = require database_path('migrations/2026_10_21_215457_add_database_triggers_for_flutter_sync_changes.php');
    $migration->down();
    $migration->up();
    $client = Client::factory()->create();
    $cursor = app(App\Services\FlutterSyncService::class)->getLatestWatermark($this->store->id);
    Client::query()->whereKey($client->id)->update(['full_name' => 'Import Xaridor']);
    $response = $this->withToken($this->token)->getJson($this->prefix . '/delta?cursor=' . $cursor)->assertOk();
    $change   = collect($response->json('data.changes'))->firstWhere('entity', 'client');
    expect($change['value']['full_name'])->toBe('Import Xaridor');
    $cursor = $response->json('data.next_cursor');
    $client->delete();
    $this->withToken($this->token)->getJson($this->prefix . '/delta?cursor=' . $cursor)
        ->assertOk()->assertJsonPath('data.changes.0.action', 'delete');
    $cursor = app(App\Services\FlutterSyncService::class)->getLatestWatermark($this->store->id);
    $client->restore();
    $this->withToken($this->token)->getJson($this->prefix . '/delta?cursor=' . $cursor)
        ->assertOk()->assertJsonPath('data.changes.0.action', 'upsert');
});

it('keeps a stale credit sale atomic until an administrator approves it', function (): void {
    config(['flutter.conflicts_enabled' => true]);
    $client = Client::factory()->create();
    $debtor = Debtor::withoutEvents(fn () => Debtor::query()->create(['store_id' => $this->store->id,
        'client_id'                                                              => $client->id, 'amount' => 50000, 'currency' => 'UZS', 'date' => now()->toDateString()]));
    $revision = app(App\Services\FlutterSyncService::class)->entityRevision($this->store->id, 'debtor', $debtor->id);
    $debtor->increment('amount', 10000);
    $envelope = integritySaleEnvelope($this, ['client' => ['server_id' => (string) $client->id],
        'debtor_context'                               => ['id' => (string) $debtor->id, 'base_revision' => $revision],
        'payment'                                      => ['type' => 'debt', 'cash' => '0', 'card' => '0', 'note' => 'Kelishilgan qarz']]);
    $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'conflict');
    expect(Sale::withoutGlobalScopes()->count())->toBe(0)->and($this->product->productStocks()->first()->quantity)->toBe(1);
    $this->user->givePermissionTo(Permission::findOrCreate('resolve_flutter_sync_conflicts', 'web'));
    $conflict = App\Models\FlutterSyncConflict::query()->firstOrFail();
    $this->withToken($this->token)->postJson(
        $this->prefix . '/conflicts/' . $conflict->id . '/resolve',
        ['decision' => 'apply', 'note' => 'Savdo va qarz birga tekshirildi.']
    )->assertOk();
    expect((int) $debtor->fresh()->amount)->toBe(90000)
        ->and($debtor->transactions()->first()->note)->toBe('Kelishilgan qarz')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(1);
});

it('posts each existing payment method with the correct receipt and debt totals', function (string $method, int $cash, int $card, int $paid, int $remaining): void {
    $client   = Client::factory()->create();
    $envelope = integritySaleEnvelope($this, ['client' => ['server_id' => (string) $client->id],
        'payment'                                      => ['type' => $method, 'cash' => (string) $cash, 'card' => (string) $card]]);
    $response = $this->withToken($this->token)->postJson($this->prefix . '/operations', ['operations' => [$envelope]])
        ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    $sale = Sale::withoutGlobalScopes()->findOrFail($response->json('data.results.0.canonical_id'));
    expect((int) $sale->paid_amount)->toBe($paid)->and((int) $sale->remaining_amount)->toBe($remaining)
        ->and((int) Debtor::withoutGlobalScopes()->sum('amount'))->toBe($method === 'preorder' ? 0 : $remaining)
        ->and($sale->status)->toBe($method === 'preorder' ? Sale::STATUS_PENDING : Sale::STATUS_COMPLETED);
})->with([
    ['cash', 30000, 0, 30000, 0], ['card', 0, 30000, 30000, 0],
    ['transfer', 0, 0, 30000, 0], ['mixed', 10000, 20000, 30000, 0],
    ['partial', 10000, 0, 10000, 20000], ['debt', 0, 0, 0, 30000], ['preorder', 0, 0, 0, 30000],
]);
