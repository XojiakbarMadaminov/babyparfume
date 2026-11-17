<?php

namespace App\Http\Controllers;

use Laravel\Passport\Token;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use App\Models\McpConnection;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\ClientRepository;
use App\Http\Requests\McpClientRequest;
use Psr\Http\Message\ResponseInterface;
use App\Http\Requests\McpConsentRequest;
use Psr\Http\Message\ServerRequestInterface;
use League\OAuth2\Server\AuthorizationServer;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\HandlesOAuthErrors;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;

class McpOAuthController extends Controller
{
    use ConvertsPsrResponses, HandlesOAuthErrors, RetrievesAuthRequestFromSession;

    public function register(McpClientRequest $request, ClientRepository $clients): JsonResponse
    {
        $data   = $request->validated();
        $client = $clients->createAuthorizationCodeGrantClient($data['client_name'], $data['redirect_uris'], false);
        $client->forceFill(['provider' => 'mcp_users'])->save();

        return response()->json([
            'client_id'                  => $client->id, 'client_name' => $client->name, 'redirect_uris' => $client->redirect_uris,
            'grant_types'                => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none', 'scope' => 'mcp:use',
        ], 201);
    }

    public function authorize(Request $request, ServerRequestInterface $psrRequest, ResponseInterface $psrResponse, AuthorizationViewResponse $view, AuthorizationController $controller): Response|AuthorizationViewResponse
    {
        abort_unless($request->query('code_challenge_method') === 'S256' && is_string($request->query('code_challenge')) && preg_match('/^[A-Za-z0-9_-]{43}$/', $request->query('code_challenge')), 400, 'PKCE S256 kerak.');
        abort_unless($request->query('scope', 'mcp:use') === 'mcp:use', 400);
        $client = Client::query()->whereKey($request->query('client_id'))->where('provider', 'mcp_users')->where('revoked', false)->first();
        abort_unless($client, 400);
        if ($request->has('resource')) {
            abort_unless($request->query('resource') === route('mcp.endpoint'), 400);
        }
        $request->merge(['prompt' => 'consent']);
        $params = [...$psrRequest->getQueryParams(), 'scope' => 'mcp:use', 'prompt' => 'consent'];

        $response = $controller->authorize($psrRequest->withQueryParams($params), $request, $psrResponse, $view);
        $request->attributes->set('mcp_oauth_callback_uri', $request->query('redirect_uri') ?? $client->redirect_uris[0] ?? null);

        return $response;
    }

    public function approve(McpConsentRequest $request, AuthorizationServer $server, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        abort_unless((string) $authRequest->getUser()?->getIdentifier() === (string) $request->user()->id, 403);
        $clientId = $authRequest->getClient()->getIdentifier();
        $client   = Client::query()->whereKey($clientId)->where('provider', 'mcp_users')->where('revoked', false)->first();
        abort_unless($client, 403);
        request()->attributes->set('mcp_oauth_callback_uri', $authRequest->getRedirectUri() ?? $client->redirect_uris[0] ?? null);
        $data = $request->validated();

        return $request->user()->getConnection()->transaction(function () use ($request, $server, $psrResponse, $authRequest, $clientId, $data): Response {
            $user       = \App\Models\User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $connection = McpConnection::query()->firstOrNew(['user_id' => $user->id, 'client_id' => $clientId]);
            $this->revokeTokens($user->id, $clientId);
            $connection->forceFill([
                'store_ids'   => array_map('intval', $data['store_ids']), 'default_store_id' => (int) $data['default_store_id'],
                'permissions' => array_values($data['permissions']), 'revoked_at' => null,
            ])->save();
            $connection->operations()->where('status', 'pending')->update(['status' => 'cancelled']);
            $authRequest->setAuthorizationApproved(true);
            $response = $this->withErrorHandling(fn () => $this->convertResponse($server->completeAuthorizationRequest($authRequest, $psrResponse)));
            abort_unless($response->isRedirection(), 400, 'Ulanishni qayta boshlang.');

            return $response;
        });
    }

    public function revokeTokens(int $userId, string $clientId): void
    {
        \Laravel\Passport\AuthCode::query()->where('user_id', $userId)->where('client_id', $clientId)->update(['revoked' => true]);
        $tokens = Token::query()->where('user_id', $userId)->where('client_id', $clientId);
        RefreshToken::query()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
    }
}
