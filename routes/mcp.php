<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateMcpWeb;
use App\Http\Controllers\McpOAuthController;
use App\Http\Controllers\McpConnectionController;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::get('/.well-known/oauth-protected-resource', fn () => response()->json([
    'resource' => route('mcp.endpoint'), 'authorization_servers' => [url('/')], 'scopes_supported' => ['mcp:use'],
]))->name('mcp.resource-metadata');
Route::get('/.well-known/oauth-protected-resource/mcp', fn () => response()->json([
    'resource' => route('mcp.endpoint'), 'authorization_servers' => [url('/')], 'scopes_supported' => ['mcp:use'],
]));

Route::get('/.well-known/oauth-authorization-server', fn () => response()->json([
    'issuer'         => url('/'), 'authorization_endpoint' => route('mcp.oauth.authorize'),
    'token_endpoint' => route('mcp.oauth.token'),
    ...(config('mcp.registration_enabled') ? ['registration_endpoint' => route('mcp.oauth.register')] : []),
    'response_types_supported'              => ['code'], 'code_challenge_methods_supported' => ['S256'],
    'grant_types_supported'                 => ['authorization_code', 'refresh_token'], 'scopes_supported' => ['mcp:use'],
    'token_endpoint_auth_methods_supported' => ['none'],
]));

Route::post('/mcp/oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:mcp-register')->name('mcp.oauth.register');
Route::post('/mcp/oauth/token', [AccessTokenController::class, 'issueToken'])->middleware('throttle:mcp-oauth')->name('mcp.oauth.token');

Route::middleware(['web', AuthenticateMcpWeb::class, 'throttle:mcp-oauth'])->group(function (): void {
    Route::get('/mcp/oauth/authorize', [McpOAuthController::class, 'authorize'])->name('mcp.oauth.authorize');
    Route::post('/mcp/oauth/authorize', [McpOAuthController::class, 'approve'])->name('mcp.oauth.approve');
    Route::delete('/mcp/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('mcp.oauth.deny');
    Route::get('/mcp/connections', [McpConnectionController::class, 'index'])->name('mcp.connections.index');
    Route::post('/mcp/connections/{connection}/revoke', [McpConnectionController::class, 'revoke'])->name('mcp.connections.revoke');
    Route::post('/mcp/connections/{connection}/default-store', [McpConnectionController::class, 'defaultStore'])->name('mcp.connections.default-store');
    Route::get('/mcp/operations/{operation}', [McpConnectionController::class, 'operation'])->whereUuid('operation')->name('mcp.operations.show');
    Route::post('/mcp/operations/{operation}/approve', [McpConnectionController::class, 'approve'])->whereUuid('operation')->name('mcp.operations.approve');
    Route::post('/mcp/operations/{operation}/reject', [McpConnectionController::class, 'reject'])->whereUuid('operation')->name('mcp.operations.reject');
});
