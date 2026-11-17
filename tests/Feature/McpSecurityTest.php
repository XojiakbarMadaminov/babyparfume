<?php

use Illuminate\Support\Str;
use App\Services\McpDebtService;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ctx        = mcpTestContext();
    $this->connection = mcpTestConnection($this->ctx['user'], [$this->ctx['storeA']->id, $this->ctx['storeB']->id]);
});

it('requires a bearer token even with an authenticated web session and hides disabled endpoints', function (): void {
    $this->actingAs($this->ctx['user'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
    config()->set('mcp.enabled', false);
    $this->get('/mcp/connections')->assertNotFound();
    $this->postJson('/mcp')->assertNotFound();
    $this->postJson('/mcp/oauth/register', [])->assertNotFound();
});

it('authenticates actual oauth tokens and preserves the current store', function (): void {
    $tokens   = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $response = mcpTestCall($this, $tokens['access_token'], 'get_account')->assertOk()->assertJsonPath('result.isError', false);
    $data     = json_decode($response->json('result.content.0.text'), true);
    expect($data['default_store_id'])->toBe($this->ctx['storeA']->id)
        ->and($data['stores'])->toHaveCount(2)
        ->and($this->ctx['user']->fresh()->current_store_id)->toBe($this->ctx['storeA']->id);
});

it('isolates record ids and rejects stores outside the connection', function (): void {
    $debtor = mcpTestDebtor($this->ctx['storeB']);
    $tokens = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    mcpTestCall($this, $tokens['access_token'], 'get_debtor', ['debtor_id' => $debtor->id, 'store_id' => $this->ctx['storeA']->id])
        ->assertOk()->assertJsonPath('result.isError', true);
    mcpTestCall($this, $tokens['access_token'], 'list_debtors', ['store_id' => $this->ctx['foreignStore']->id])
        ->assertOk()->assertJsonPath('result.isError', true);
    $response = mcpTestCall($this, $tokens['access_token'], 'get_debtor', ['debtor_id' => $debtor->id, 'store_id' => $this->ctx['storeB']->id])
        ->assertOk()->assertJsonPath('result.isError', false);
    expect(json_decode($response->json('result.content.0.text'), true)['amount'])->toBe('500000');
});

it('rechecks current permissions and revocation on every request', function (): void {
    $tokens = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $this->ctx['user']->revokePermissionTo('ViewAny:Debtor');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    mcpTestCall($this, $tokens['access_token'], 'list_debtors')->assertOk()->assertJsonPath('result.isError', true);
    $this->connection->refresh()->forceFill(['revoked_at' => now()])->save();
    mcpTestCall($this, $tokens['access_token'], 'get_account')->assertForbidden();
});

it('does not expose profits without the cost visibility permission', function (): void {
    $tokens   = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $response = mcpTestCall($this, $tokens['access_token'], 'get_sales_report', ['start' => now()->toDateString(), 'end' => now()->toDateString()])
        ->assertOk()->assertJsonPath('result.isError', false);
    $report = json_decode($response->json('result.content.0.text'), true);
    expect($report)->not->toHaveKey('profit')->not->toHaveKey('marginPercent');
});

it('prevents another user from approving or seeing an operation', function (): void {
    $debtor    = mcpTestDebtor($this->ctx['storeA']);
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $debtor->id, 'amount' => 300000,
    ]);
    $other = App\Models\User::factory()->create();
    $this->actingAs($other)->get('/mcp/operations/' . $operation->id)->assertForbidden();
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertForbidden();
    expect($debtor->fresh()->amount)->toBe(500000);
});

it('keeps proposal store fixed when the connection default changes', function (): void {
    $debtor    = mcpTestDebtor($this->ctx['storeA']);
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $debtor->id, 'amount' => 300000,
    ]);
    $this->actingAs($this->ctx['user'])->post('/mcp/connections/' . $this->connection->id . '/default-store', [
        'store_id' => $this->ctx['storeB']->id,
    ])->assertRedirect();
    $this->post('/mcp/operations/' . $operation->id . '/approve', ['confirmation' => 1])->assertRedirect();
    expect($operation->fresh()->result['store_id'])->toBe($this->ctx['storeA']->id)
        ->and($this->ctx['user']->fresh()->current_store_id)->toBe($this->ctx['storeA']->id);
});

it('scopes product quantities to the requested store and hides cost fields', function (): void {
    $stockA = App\Models\Stock::query()->create(['name' => 'Stock A']);
    $stockB = App\Models\Stock::query()->create(['name' => 'Stock B']);
    $this->ctx['storeA']->stocks()->attach($stockA->id);
    $this->ctx['storeB']->stocks()->attach($stockB->id);
    $product = App\Models\Product::factory()->create(['name' => 'Parfum', 'store_id' => $this->ctx['storeB']->id, 'initial_price' => 10000]);
    App\Models\ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $stockA->id, 'quantity' => 2]);
    App\Models\ProductStock::query()->create(['product_id' => $product->id, 'stock_id' => $stockB->id, 'quantity' => 7]);
    $tokens   = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $response = mcpTestCall($this, $tokens['access_token'], 'search_products', ['store_id' => $this->ctx['storeB']->id])->assertOk()->assertJsonPath('result.isError', false);
    $data     = json_decode($response->json('result.content.0.text'), true);
    expect($data['data'][0]['quantity'])->toBe(7)->and($data['data'][0])->not->toHaveKey('initial_price');
});

it('rejects write tools for read only connections and exposes no execution tool', function (): void {
    $this->connection->forceFill(['permissions' => ['debts:read']])->save();
    $tokens = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    mcpTestCall($this, $tokens['access_token'], 'prepare_debt_change', [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => 1, 'amount' => 300000,
    ])->assertOk()->assertJsonPath('result.isError', true);
    $response = mcpTestCall($this, $tokens['access_token'], 'execute_debt_change');
    expect($response->json('error.code'))->toBe(-32602);
    $this->assertDatabaseCount('mcp_operations', 0);
});

it('rejects foreign origins and excessive payloads without leaking data', function (): void {
    $this->withHeader('Origin', 'https://untrusted.example')->postJson('/mcp', [])->assertForbidden();
    $this->flushHeaders();
    $this->postJson('/mcp', ['payload' => str_repeat('a', 65537)])->assertStatus(413);
});
