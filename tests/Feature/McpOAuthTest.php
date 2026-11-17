<?php

use Laravel\Passport\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ctx        = mcpTestContext();
    $this->connection = mcpTestConnection($this->ctx['user'], [$this->ctx['storeA']->id]);
});

it('supports authorization code pkce and refresh while preserving mobile sanctum login', function (): void {
    $tokens    = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $refreshed = $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $this->connection->client_id, 'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->json();
    expect($refreshed['access_token'])->not->toBe($tokens['access_token']);
    mcpTestCall($this, $refreshed['access_token'], 'get_account')->assertOk()->assertJsonPath('result.isError', false);
    $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $this->connection->client_id, 'refresh_token' => $tokens['refresh_token'],
    ])->assertBadRequest();
    $response = $this->postJson('/api/flutter/v1/login', [
        'email'       => $this->ctx['user']->email, 'password' => 'password', 'device_uuid' => (string) Illuminate\Support\Str::uuid(),
        'device_name' => 'Test mobile', 'platform' => 'android',
    ])->assertCreated();
    $this->withToken($response->json('data.token'))->getJson('/api/flutter/v1/session')->assertOk();
    mcpTestCall($this, $response->json('data.token'), 'get_account')->assertUnauthorized();
});

it('validates dynamic registration without accepting unsafe redirects or unsupported grants', function (): void {
    $this->postJson('/mcp/oauth/register', [
        'client_name' => 'Agent', 'redirect_uris' => ['https://agent.example/callback'], 'token_endpoint_auth_method' => 'none',
    ])->assertCreated()->assertJsonPath('scope', 'mcp:use');
    foreach (['javascript:alert(1)', 'http://example.com/callback', 'https://user:pass@example.com/callback', 'https://example.com/callback#fragment'] as $url) {
        $this->postJson('/mcp/oauth/register', ['client_name' => 'Agent', 'redirect_uris' => [$url]])->assertUnprocessable();
    }
});

it('rejects missing pkce or plain challenges', function (): void {
    $params = ['client_id' => $this->connection->client_id, 'redirect_uri' => 'https://agent.example/callback', 'response_type' => 'code'];
    $this->actingAs($this->ctx['user'])->get('/mcp/oauth/authorize?' . http_build_query($params))->assertBadRequest();
    $this->get('/mcp/oauth/authorize?' . http_build_query([...$params, 'code_challenge_method' => 'plain', 'code_challenge' => str_repeat('x', 43)]))->assertBadRequest();
});

it('revokes access and refresh tokens when the user disconnects the client', function (): void {
    $tokens = mcpTestLogin($this, $this->ctx['user'], $this->connection);
    $this->actingAs($this->ctx['user'])->post('/mcp/connections/' . $this->connection->id . '/revoke')->assertRedirect();
    mcpTestCall($this, $tokens['access_token'], 'get_account')->assertUnauthorized();
    $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $this->connection->client_id, 'refresh_token' => $tokens['refresh_token'],
    ])->assertBadRequest();
    expect(Token::query()->where('user_id', $this->ctx['user']->id)->where('revoked', false)->count())->toBe(0);
});

it('requires csrf for web approvals and consent', function (): void {
    app()->instance('env', 'local');
    $this->actingAs($this->ctx['user'])->post('/mcp/oauth/authorize', [])->assertStatus(419);
    $this->post('/mcp/operations/' . Illuminate\Support\Str::uuid() . '/approve', ['confirmation' => 1])->assertStatus(419);
});

it('keeps browser consent same origin and allows only the validated oauth callback', function (): void {
    $redirectUri = 'https://chatgpt.com/connector/oauth/test-connector';
    Laravel\Passport\Client::query()->findOrFail($this->connection->client_id)
        ->forceFill(['redirect_uris' => [$redirectUri]])->save();
    $verifier = str_repeat('b', 64);
    $params   = [
        'client_id'             => $this->connection->client_id,
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'state'                 => 'browser-consent-state',
        'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
    $this->actingAs($this->ctx['user'], 'web')
        ->get('/mcp/oauth/authorize?' . http_build_query($params))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://chatgpt.com");

    $response = $this->withHeader('Origin', rtrim(config('app.url'), '/'))
        ->post('/mcp/oauth/authorize', [
            'auth_token'       => session('authToken'),
            'store_ids'        => $this->connection->store_ids,
            'default_store_id' => $this->connection->default_store_id,
            'permissions'      => $this->connection->permissions,
        ])->assertRedirect()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://chatgpt.com");

    expect($response->headers->get('Location'))->toStartWith($redirectUri . '?');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('browser-consent-state');
    $this->flushHeaders();
    $this->postJson('/mcp/oauth/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => $this->connection->client_id,
        'redirect_uri'  => $redirectUri,
        'code_verifier' => $verifier,
        'code'          => $query['code'],
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
});

it('does not allow an unregistered callback into the consent policy', function (): void {
    $params = [
        'client_id'             => $this->connection->client_id,
        'redirect_uri'          => 'https://untrusted.example/callback',
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'code_challenge'        => str_repeat('x', 43),
        'code_challenge_method' => 'S256',
    ];
    $this->actingAs($this->ctx['user'], 'web')
        ->get('/mcp/oauth/authorize?' . http_build_query($params))->assertUnauthorized();
    expect(request()->attributes->has('mcp_oauth_callback_uri'))->toBeFalse();
});

it('continues to reject opaque and foreign origins on oauth consent', function (string $origin): void {
    $this->actingAs($this->ctx['user'], 'web')->withHeader('Origin', $origin)
        ->post('/mcp/oauth/authorize', [])->assertForbidden();
    $this->assertDatabaseCount('oauth_auth_codes', 0);
})->with(['opaque' => 'null', 'foreign' => 'https://untrusted.example']);
