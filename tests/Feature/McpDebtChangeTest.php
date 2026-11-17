<?php

use App\Models\McpDelivery;
use Illuminate\Support\Str;
use App\Services\McpDebtService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->ctx        = mcpTestContext();
    $this->connection = mcpTestConnection($this->ctx['user'], [$this->ctx['storeA']->id, $this->ctx['storeB']->id]);
    $this->debtor     = mcpTestDebtor($this->ctx['storeA']);
    $this->actingAs($this->ctx['user']);
});

it('prepares without modifying the balance or sending any notifications', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 300000,
    ]);
    expect($operation->status)->toBe('pending')->and($this->debtor->fresh()->amount)->toBe(500000);
    $this->assertDatabaseCount('mcp_deliveries', 0);
    Queue::assertNothingPushed();
    $this->get('/mcp/operations/' . $operation->id)->assertOk()->assertSee('Chilonzor')->assertSee('Anvar Aliyev');
});

it('applies approved debt changes once with one firebase record per change', function (string $type, int $amount, int $expected): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => $type, 'debtor_id' => $this->debtor->id, 'amount' => $amount,
    ]);
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertRedirect();
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertRedirect();
    expect($this->debtor->fresh()->amount)->toBe($expected)
        ->and($operation->fresh()->status)->toBe('applied')
        ->and($this->debtor->transactions()->count())->toBe(1);
    expect(McpDelivery::query()->where('operation_id', $operation->id)->where('channel', 'firebase')->count())->toBe(1);
    expect(McpDelivery::query()->where('operation_id', $operation->id)->whereIn('channel', ['sms', 'telegram'])->count())->toBe(2);
})->with([
    'add debt'           => ['debt', 300000, 800000],
    'payment'            => ['payment', 100000, 400000],
    'set balance higher' => ['edit', 600000, 600000],
    'set balance lower'  => ['edit', 300000, 300000],
    'close debt'         => ['edit', 0, 0],
]);

it('creates a new debtor only after human approval and queues all channels', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'create', 'full_name' => 'Vali Valiyev',
        'phone'      => '+998909876543', 'amount' => 300000, 'date' => now()->toDateString(),
    ]);
    $this->assertDatabaseCount('debtors', 1);
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertRedirect();
    $this->assertDatabaseCount('debtors', 2);
    expect($operation->fresh()->result['amount'])->toBe(300000);
    $this->assertDatabaseCount('mcp_deliveries', 3);
});

it('requires actual confirmation and rejects stale or expired proposals', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'payment', 'debtor_id' => $this->debtor->id, 'amount' => 100000,
    ]);
    $this->postJson('/mcp/operations/' . $operation->id . '/approve', ['confirmed' => true])->assertUnprocessable();
    App\Models\Debtor::withoutEvents(fn () => $this->debtor->update(['amount' => 450000]));
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertConflict();
    $operation->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertConflict();
    $this->assertDatabaseCount('mcp_deliveries', 0);
});

it('rejects changed idempotency payloads and excessive payments', function (): void {
    $data    = ['request_id' => (string) Str::uuid(), 'type' => 'payment', 'debtor_id' => $this->debtor->id, 'amount' => 100000];
    $service = app(McpDebtService::class);
    $first   = $service->prepare($this->connection, $this->ctx['user'], $data);
    expect($service->prepare($this->connection, $this->ctx['user'], $data)->id)->toBe($first->id);
    expect(fn () => $service->prepare($this->connection, $this->ctx['user'], [...$data, 'amount' => 200000]))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->prepare($this->connection, $this->ctx['user'], [...$data, 'request_id' => (string) Str::uuid(), 'amount' => 600000]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('notifies firebase for a metadata edit without inventing a payment or duplicating sms', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'edit', 'debtor_id' => $this->debtor->id,
        'amount'     => 500000, 'note' => 'MCP izohi',
    ]);
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertRedirect();
    expect($this->debtor->fresh()->note)->toBe('MCP izohi')->and($this->debtor->transactions()->count())->toBe(0);
    $this->assertDatabaseCount('mcp_deliveries', 1);
});

it('blocks approval when write mode or the users store access is revoked', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 300000,
    ]);
    config()->set('mcp.writes_enabled', false);
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertForbidden();
    config()->set('mcp.writes_enabled', true);
    $this->ctx['user']->stores()->detach($this->ctx['storeA']->id);
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertNotFound();
    expect($this->debtor->fresh()->amount)->toBe(500000);
});

it('rolls back the balance and notifications when the operation fails', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 300000,
    ]);
    $this->mock(App\Services\McpNotificationService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('test failure'));
    expect(fn () => app(McpDebtService::class)->apply($this->ctx['user'], $operation))->toThrow(RuntimeException::class);
    expect($this->debtor->fresh()->amount)->toBe(500000)->and($operation->fresh()->status)->toBe('pending');
    $this->assertDatabaseCount('mcp_deliveries', 0);
    $this->assertDatabaseCount('debtor_transactions', 0);
    expect(request()->attributes->has('mcp_operation_id'))->toBeFalse();
});

it('rejects a second proposal based on an already changed balance', function (): void {
    $data   = ['type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 100000];
    $first  = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [...$data, 'request_id' => (string) Str::uuid()]);
    $second = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [...$data, 'request_id' => (string) Str::uuid()]);
    $this->post('/mcp/operations/' . $first->id . '/approve', ['confirmation' => 1])->assertRedirect();
    $this->post('/mcp/operations/' . $second->id . '/approve', ['confirmation' => 1])->assertConflict();
    expect($this->debtor->fresh()->amount)->toBe(600000)->and($this->debtor->transactions()->count())->toBe(1);
});

it('does not silently reuse a phone number belonging to a differently named client', function (): void {
    expect(fn () => app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'create', 'full_name' => 'Other person',
        'phone'      => $this->debtor->client->phone, 'amount' => 300000, 'date' => now()->toDateString(),
    ]))->toThrow(Illuminate\Validation\ValidationException::class);
    $this->assertDatabaseCount('mcp_operations', 0);
});
