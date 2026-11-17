<?php

namespace App\Http\Middleware;

use Closure;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Passport\Exceptions\OAuthServerException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthException;

class LogMcpTokenRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs('mcp.oauth.token')) {
            return $next($request);
        }
        $requestId = (string) Str::uuid();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $errorResponse = $exception instanceof HttpResponseException ? $exception->getResponse() : null;
            $status        = $errorResponse?->getStatusCode() ?? ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $this->record($request, $requestId, $status, $exception, $errorResponse);
            if ($errorResponse instanceof Response) {
                $this->decorate($request, $errorResponse, $requestId);
            }

            throw $exception;
        }
        $this->record($request, $requestId, $response->getStatusCode(), response: $response);

        return $this->decorate($request, $response, $requestId);
    }

    private function decorate(Request $request, Response $response, string $requestId): Response
    {
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($request->attributes->get('mcp_token_origin_allowed') === true) {
            $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin'));
            $response->setVary('Origin', false);
        }

        return $response;
    }

    private function record(Request $request, string $requestId, int $status, ?Throwable $exception = null, ?Response $response = null): void
    {
        $grant      = $request->input('grant_type');
        $oauth      = $exception instanceof OAuthServerException ? $exception->getPrevious() : null;
        $oauthError = $oauth instanceof LeagueOAuthException ? $oauth->getErrorType() : null;
        $hint       = $oauth instanceof LeagueOAuthException ? $oauth->getHint() : null;
        $reason     = $request->attributes->get('mcp_token_rejection_reason');
        if ($oauthError === null && $status >= 400 && $response !== null
            && str_starts_with((string) $response->headers->get('Content-Type'), 'application/json')) {
            $body = json_decode((string) $response->getContent(), true, 8);
            if (is_array($body) && in_array($body['error'] ?? null, [
                'invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client',
                'unsupported_grant_type', 'invalid_scope', 'access_denied', 'server_error', 'temporarily_unavailable',
            ], true)) {
                $oauthError = $body['error'];
                $hint       = is_string($body['hint'] ?? null) ? $body['hint'] : null;
            }
        }
        if ($oauthError !== null) {
            $reason ??= $this->oauthReason($hint, $oauthError);
        }
        Log::log($status >= 400 ? 'error' : 'info', 'MCP OAuth token request finished', [
            'request_id'               => $requestId,
            'http_status'              => $status,
            'grant_type'               => in_array($grant, ['authorization_code', 'refresh_token'], true) ? $grant : 'unsupported_or_missing',
            'body_format'              => $request->isJson() ? 'json' : ($request->getContentTypeFormat() === 'form' ? 'form' : 'other'),
            'has_client_id'            => $request->filled('client_id') || $request->getUser() !== null,
            'has_client_secret'        => $request->filled('client_secret'),
            'has_authorization_header' => $request->hasHeader('Authorization'),
            'has_code'                 => $request->filled('code'),
            'has_code_verifier'        => $request->filled('code_verifier'),
            'has_refresh_token'        => $request->filled('refresh_token'),
            'has_redirect_uri'         => $request->filled('redirect_uri'),
            'has_origin'               => $request->hasHeader('Origin'),
            'origin_allowed'           => $request->attributes->get('mcp_token_origin_allowed'),
            'oauth_error'              => $oauthError,
            'reason'                   => $reason ?? match (true) {
                $status === 429 => 'rate_limited',
                $status === 413 => 'payload_too_large',
                $status >= 500  => 'internal_error',
                $status >= 400  => 'request_rejected',
                default         => 'issued',
            },
            'exception_type' => $exception ? $exception::class : null,
        ]);
    }

    private function oauthReason(?string $hint, string $oauthError): string
    {
        return match ($hint) {
            'Failed to verify `code_verifier`.'                         => 'pkce_verification_failed',
            'Code Verifier must follow the specifications of RFC-7636.' => 'pkce_format_invalid',
            'Cannot validate the provided authorization code'           => 'authorization_code_invalid',
            'Issue decrypting the authorization code'                   => 'authorization_code_decryption_failed',
            'Authorization code malformed'                              => 'authorization_code_malformed',
            'Authorization code has expired'                            => 'authorization_code_expired',
            'Authorization code has been revoked'                       => 'authorization_code_revoked',
            'Authorization code was not issued to this client'          => 'authorization_code_client_mismatch',
            'Invalid redirect URI'                                      => 'redirect_uri_mismatch',
            'Check the `client_id` parameter'                           => 'client_id_missing',
            'Check the `client_secret` parameter'                       => 'client_secret_missing',
            'Check the `code` parameter'                                => 'authorization_code_missing',
            'Check the `code_verifier` parameter'                       => 'code_verifier_missing',
            'Check the `redirect_uri` parameter'                        => 'redirect_uri_missing',
            'Check the `refresh_token` parameter'                       => 'refresh_token_missing',
            'Cannot decrypt the refresh token'                          => 'refresh_token_decryption_failed',
            'Token is not linked to client'                             => 'refresh_token_client_mismatch',
            'Token has expired'                                         => 'refresh_token_expired',
            'Token has been revoked'                                    => 'refresh_token_revoked',
            default                                                     => match ($oauthError) {
                'invalid_client'         => 'client_authentication_failed',
                'unauthorized_client'    => 'client_grant_not_allowed',
                'unsupported_grant_type' => 'unsupported_grant_type',
                'invalid_scope'          => 'scope_invalid',
                default                  => 'oauth_rejected',
            },
        };
    }
}
