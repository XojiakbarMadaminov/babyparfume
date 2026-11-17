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
