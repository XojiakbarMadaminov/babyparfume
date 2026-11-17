<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function mcpTestKeys(): void
{
    static $privateKey = null;
    static $publicKey  = null;
    if ($privateKey === null) {
        $key        = phpseclib4\Crypt\RSA::createKey(2048);
        $privateKey = (string) $key;
        $publicKey  = (string) $key->getPublicKey();
    }
    config()->set('passport.private_key', $privateKey);
    config()->set('passport.public_key', $publicKey);
}

/** @return array{user: App\Models\User, storeA: App\Models\Store, storeB: App\Models\Store, foreignStore: App\Models\Store} */
function mcpTestContext(): array
{
    config()->set(['mcp.enabled' => true, 'mcp.writes_enabled' => true, 'flutter.enabled' => true]);
    mcpTestKeys();
    $storeA       = App\Models\Store::query()->create(['name' => 'Chilonzor', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $storeB       = App\Models\Store::query()->create(['name' => 'Yunusobod', 'address' => 'Toshkent', 'phone' => '+998902222222']);
    $foreignStore = App\Models\Store::query()->create(['name' => 'Begona', 'address' => 'Toshkent', 'phone' => '+998903333333']);
    $user         = App\Models\User::factory()->create(['current_store_id' => $storeA->id]);
    $user->stores()->attach([$storeA->id, $storeB->id]);
    foreach (['ViewAny:Debtor', 'View:Debtor', 'Create:Debtor', 'Update:Debtor', 'ViewAny:Product', 'View:Pos', 'view_mobile_sales_statistics'] as $name) {
        $permission = Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
        $user->givePermissionTo($permission);
    }

    return compact('user', 'storeA', 'storeB', 'foreignStore');
}

/** @param list<int> $storeIds
 * @param  list<string>  $permissions
 */
function mcpTestConnection(App\Models\User $user, array $storeIds, array $permissions = ['products:read', 'debts:read', 'sales:read', 'debts:write']): App\Models\McpConnection
{
    $client = app(Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient('Test agent', ['https://agent.example/callback'], false);
    $client->forceFill(['provider' => 'mcp_users'])->save();

    return App\Models\McpConnection::query()->create([
        'user_id'          => $user->id, 'client_id' => $client->id, 'store_ids' => $storeIds,
        'default_store_id' => $storeIds[0] ?? null, 'permissions' => $permissions,
    ]);
}

function mcpTestDebtor(App\Models\Store $store, int $amount = 500000): App\Models\Debtor
{
    $client = App\Models\Client::query()->create(['full_name' => 'Anvar Aliyev', 'phone' => '+99890' . fake()->unique()->numerify('#######')]);

    return App\Models\Debtor::withoutEvents(fn () => App\Models\Debtor::query()->create([
        'store_id' => $store->id, 'client_id' => $client->id, 'amount' => $amount, 'currency' => 'UZS', 'date' => now()->toDateString(),
    ]));
}

/** @return array<string, mixed> */
function mcpTestLogin(Tests\TestCase $test, App\Models\User $user, App\Models\McpConnection $connection): array
{
    $verifier = str_repeat('a', 64);
    $params   = [
        'client_id'      => $connection->client_id, 'redirect_uri' => 'https://agent.example/callback',
        'response_type'  => 'code', 'scope' => 'mcp:use', 'state' => 'test-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
    ];
    $test->actingAs($user, 'web')->get('/mcp/oauth/authorize?' . http_build_query($params))->assertOk();
    $response = $test->post('/mcp/oauth/authorize', [
        'auth_token'       => session('authToken'), 'store_ids' => $connection->store_ids,
        'default_store_id' => $connection->default_store_id, 'permissions' => $connection->permissions,
    ])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('test-state');

    return $test->postJson('/mcp/oauth/token', [
        'grant_type'   => 'authorization_code', 'client_id' => $connection->client_id,
        'redirect_uri' => 'https://agent.example/callback', 'code_verifier' => $verifier, 'code' => $query['code'],
    ])->assertOk()->json();
}

/** @param array<string, mixed> $arguments */
function mcpTestCall(Tests\TestCase $test, string $token, string $name, array $arguments = []): Illuminate\Testing\TestResponse
{
    return $test->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments],
    ]);
}
