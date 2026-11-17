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
    ])->assertCreated()->assertJsonPath('scope', 'mcp:use')
        ->assertJsonPath('redirect_uris', ['https://agent.example/callback'])
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('grant_types', ['authorization_code', 'refresh_token']);
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

it('validates pkce for form encoded token requests from the registered client origin', function (bool $correctVerifier): void {
    $redirectUri = 'https://chatgpt.com/connector/oauth/test-token-client';
    Laravel\Passport\Client::query()->findOrFail($this->connection->client_id)
        ->forceFill(['redirect_uris' => [$redirectUri]])->save();
    $verifier = str_repeat('c', 64);
    $params   = [
        'client_id'             => $this->connection->client_id, 'redirect_uri' => $redirectUri,
        'response_type'         => 'code', 'scope' => 'mcp:use', 'state' => 'form-token-state',
        'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
    $this->actingAs($this->ctx['user'], 'web')->get('/mcp/oauth/authorize?' . http_build_query($params))->assertOk();
    $response = $this->post('/mcp/oauth/authorize', [
        'auth_token'       => session('authToken'), 'store_ids' => $this->connection->store_ids,
        'default_store_id' => $this->connection->default_store_id, 'permissions' => $this->connection->permissions,
    ])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    Illuminate\Support\Facades\Log::spy();
    $tokenResponse = $this->withHeader('Origin', 'https://chatgpt.com')->post('/mcp/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $this->connection->client_id,
        'code'       => $query['code'], 'code_verifier' => $correctVerifier ? $verifier : str_repeat('d', 64), 'redirect_uri' => $redirectUri,
        'resource'   => route('mcp.endpoint'),
    ])->assertHeader('Access-Control-Allow-Origin', 'https://chatgpt.com');
    if (!$correctVerifier) {
        $tokenResponse->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error'
            && $message === 'MCP OAuth token request finished'
            && $context['oauth_error'] === 'invalid_grant'
            && $context['reason'] === 'pkce_verification_failed')->once();

        return;
    }
    $tokenResponse->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message, array $context) use ($tokenResponse): bool {
        expect($level)->toBe('info')
            ->and($message)->toBe('MCP OAuth token request finished')
            ->and($context['reason'])->toBe('issued')
            ->and($context['oauth_error'])->toBeNull()
            ->and(json_encode($context, JSON_THROW_ON_ERROR))
            ->not->toContain($tokenResponse->json('access_token'))
            ->not->toContain($tokenResponse->json('refresh_token'));

        return true;
    })->once();
    $this->post('/mcp/oauth/token', [
        'grant_type'    => 'refresh_token', 'client_id' => $this->connection->client_id,
        'refresh_token' => $tokenResponse->json('refresh_token'),
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
})->with(['correct verifier' => true, 'incorrect verifier' => false]);

it('records token failure reasons without recording credentials or authorization codes', function (): void {
    Illuminate\Support\Facades\Log::spy();
    $secrets = ['private-code-marker', 'private-verifier-marker', 'private-secret-marker', 'private-refresh-marker'];
    $this->post('/mcp/oauth/token', [
        'grant_type'    => 'authorization_code', 'client_id' => $this->connection->client_id,
        'code'          => $secrets[0], 'code_verifier' => $secrets[1], 'client_secret' => $secrets[2],
        'refresh_token' => $secrets[3], 'redirect_uri' => 'https://agent.example/callback',
    ])->assertBadRequest()->assertHeader('X-Request-ID')->assertHeader('Cache-Control', 'no-store, private');
    Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message, array $context) use ($secrets): bool {
        expect($level)->toBe('error')
            ->and($message)->toBe('MCP OAuth token request finished')
            ->and($context['http_status'])->toBe(400)
            ->and($context['has_code'])->toBeTrue()
            ->and($context['has_code_verifier'])->toBeTrue()
            ->and($context['oauth_error'])->toBe('invalid_grant')
            ->and($context['reason'])->toBe('authorization_code_invalid');
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            expect($encoded)->not->toContain($secret);
        }

        return true;
    })->once();
});

it('records standard oauth errors from rendered token responses', function (array $parameters, string $oauthError, string $reason): void {
    Illuminate\Support\Facades\Log::spy();
    $this->post('/mcp/oauth/token', $parameters)->assertClientError()->assertJsonPath('error', $oauthError);
    Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error'
        && $message === 'MCP OAuth token request finished'
        && $context['oauth_error'] === $oauthError
        && $context['reason'] === $reason)->once();
})->with([
    'unsupported grant' => [['grant_type' => 'diagnostic_unsupported_grant'], 'unsupported_grant_type', 'unsupported_grant_type'],
    'unknown client'    => [[
        'grant_type' => 'authorization_code', 'client_id' => '00000000-0000-4000-8000-000000000000',
        'code'       => 'invalid-code', 'code_verifier' => str_repeat('a', 64), 'redirect_uri' => 'https://agent.example/callback',
    ], 'invalid_client', 'client_authentication_failed'],
]);

it('rejects token origins outside the requested clients registered callbacks', function (string $origin): void {
    $this->withHeader('Origin', $origin)->post('/mcp/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $this->connection->client_id,
    ])->assertForbidden();
    $this->assertDatabaseCount('oauth_access_tokens', 0);
})->with(['unregistered' => 'https://untrusted.example', 'opaque' => 'null', 'lookalike' => 'https://agent.example.untrusted.test']);

it('does not use registered client origins to permit cross origin web consent or mcp access', function (): void {
    $this->actingAs($this->ctx['user'], 'web')->withHeader('Origin', 'https://agent.example');
    $this->post('/mcp/oauth/authorize', ['client_id' => $this->connection->client_id])->assertForbidden();
    $this->postJson('/mcp', ['client_id' => $this->connection->client_id])->assertForbidden();
});
