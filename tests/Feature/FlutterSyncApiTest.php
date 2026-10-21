<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use App\Services\FlutterSyncService;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
    $this->mock(App\Services\TelegramSaleNotifier::class)->shouldReceive('notify');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    config()->set('flutter.enabled', true);

    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->stockA = Stock::query()->create(['name' => 'Ombor A', 'is_active' => true]);
    $this->stockA->stores()->attach($this->storeA);

    $this->user = User::factory()->create([
        'email'            => 'sync_user@example.com',
        'current_store_id' => $this->storeA->id,
    ]);
    $this->user->stores()->attach($this->storeA->id);

    $role        = Role::findOrCreate('admin', 'web');
    $permissions = [
        'view_pos',
        'View:Pos',
        'view_mobile_sales_statistics',
        'ViewAny:Product',
        'View:Product',
        'Create:Product',
        'Update:Product',
        'Delete:Product',
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

    $this->deviceUuid = (string) Str::uuid();
    $loginResponse    = $this->postJson('/api/flutter/v1/login', [
        'email'       => 'sync_user@example.com',
        'password'    => 'password',
        'device_uuid' => $this->deviceUuid,
        'device_name' => 'iPhone 15',
        'platform'    => 'ios',
    ])->assertCreated();

    $this->token = $loginResponse->json('data.token');
});

it('performs bootstrap initialization and returns paginated products and debtors', function (): void {
    for ($i = 1; $i <= 3; $i++) {
        Product::query()->create([
            'store_id'      => $this->storeA->id,
            'name'          => "Mahsulot $i",
            'barcode'       => "47800000000$i",
            'type'          => Product::TYPE_PACKAGE,
            'initial_price' => 10000,
            'price'         => 15000,
        ]);
    }

    $client = Client::query()->create(['full_name' => 'Mijoz 1', 'phone' => '+998901234567']);
    Debtor::query()->create([
        'store_id'  => $this->storeA->id,
        'client_id' => $client->id,
        'amount'    => 50000,
        'currency'  => 'UZS',
        'date'      => '2026-10-21',
    ]);

    $bootstrapResp = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/bootstrap")
        ->assertOk()
        ->assertJsonPath('data.manifest.products', 3)
        ->assertJsonPath('data.manifest.debtors', 1)
        ->assertJsonPath('data.schema_version', 1);

    $snapshotId = $bootstrapResp->json('data.snapshot_id');

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/bootstrap/{$snapshotId}?entity=products&limit=2")
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.has_more', true);

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/bootstrap/{$snapshotId}?entity=debtors&limit=10")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.has_more', false);
});

it('streams delta changes based on watermark revisions', function (): void {
    $syncService = app(FlutterSyncService::class);
    $rev1        = $syncService->recordRevision($this->storeA->id, 'product', 101, 'upsert', ['name' => 'Delta Product']);
    $rev2        = $syncService->recordRevision($this->storeA->id, 'debtor', 202, 'upsert', ['amount' => '10000']);

    $response = $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/delta?cursor=0")
        ->assertOk()
        ->assertJsonCount(2, 'data.changes');

    expect($response->json('data.changes.0.entity'))->toBe('product');
    expect($response->json('data.changes.1.entity'))->toBe('debtor');

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/delta?cursor={$rev1->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.changes')
        ->assertJsonPath('data.changes.0.entity', 'debtor');
});

it('processes offline sales with zero-stock support, deficit audit, and replay', function (): void {
    $product = Product::query()->create([
        'store_id'      => $this->storeA->id,
        'name'          => 'Parfyum Nilufar',
        'barcode'       => '4789998887771',
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 20000,
        'price'         => 35000,
    ]);
    $product->productStocks()->create(['stock_id' => $this->stockA->id, 'quantity' => 0]);

    $client = Client::query()->create(['full_name' => 'Doimiy Xaridor', 'phone' => '+998909998877']);

    $opId        = (string) Str::uuid();
    $localSaleId = (string) Str::uuid();

    $salePayload = [
        'local_sale_id' => $localSaleId,
        'client'        => ['server_id' => (string) $client->id, 'local_id' => null],
        'items'         => [
            [
                'product_id' => (string) $product->id,
                'stock_id'   => (string) $this->stockA->id,
                'quantity'   => 2,
                'unit_price' => '35000.00',
                'discount'   => '0.00',
            ],
        ],
        'payment' => [
            'type' => 'cash',
            'cash' => '70000.00',
            'card' => '0.00',
        ],
        'receipt_discount' => '0.00',
        'currency'         => 'UZS',
        'scale'            => 2,
    ];

    $response = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/operations", [
            'schema_version' => 1,
            'operations'     => [
                [
                    'operation_id'   => $opId,
                    'entity_type'    => 'sale',
                    'operation_type' => 'sale.create',
                    'occurred_at'    => now()->toIso8601String(),
                    'payload'        => $salePayload,
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.results.0.status', 'applied')
        ->assertJsonPath('data.results.0.is_replay', false);

    $result = $response->json('data.results.0.response_data');
    expect($result['total_amount'])->toBe('70000.00');
    expect($result['deficits'])->toHaveCount(1);
    expect($result['deficits'][0]['requested'])->toBe(2);
    expect($result['deficits'][0]['available'])->toBe(0);

    $sale = Sale::findOrFail($result['server_sale_id']);
    expect((float) $sale->total_amount)->toBe(70000.0);

    // Exact replay test
    $replayResp = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/operations", [
            'schema_version' => 1,
            'operations'     => [
                [
                    'operation_id'   => $opId,
                    'entity_type'    => 'sale',
                    'operation_type' => 'sale.create',
                    'payload'        => $salePayload,
                ],
            ],
        ]);

    $replayResp->assertOk()
        ->assertJsonPath('data.results.0.status', 'applied')
        ->assertJsonPath('data.results.0.is_replay', true);

    expect(Sale::query()->count())->toBe(1);

    // Mismatched payload replay (conflict idempotency_mismatch)
    $tamperedPayload                    = $salePayload;
    $tamperedPayload['payment']['cash'] = '80000.00';

    $conflictResp = $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/operations", [
            'schema_version' => 1,
            'operations'     => [
                [
                    'operation_id'   => $opId,
                    'entity_type'    => 'sale',
                    'operation_type' => 'sale.create',
                    'payload'        => $tamperedPayload,
                ],
            ],
        ]);

    $conflictResp->assertOk()
        ->assertJsonPath('data.results.0.status', 'conflict')
        ->assertJsonPath('data.results.0.error', 'idempotency_mismatch');
});

it('handles operation conflict detection and records in flutter_sync_conflicts', function (): void {
    $opId = (string) Str::uuid();

    $this->withToken($this->token)
        ->postJson("/api/flutter/v1/stores/{$this->storeA->id}/operations", [
            'schema_version' => 1,
            'operations'     => [
                [
                    'operation_id'   => $opId,
                    'entity_type'    => 'debtor',
                    'operation_type' => 'debtor.transact',
                    'payload'        => [
                        'debtor_id' => 999999,
                        'type'      => 'debt',
                        'amount'    => '20000',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.status', 'conflict');

    $this->withToken($this->token)
        ->getJson("/api/flutter/v1/stores/{$this->storeA->id}/conflicts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.operation_id', $opId);
});
