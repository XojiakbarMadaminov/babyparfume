<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class McpClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('mcp.registration_enabled');
    }

    public function rules(): array
    {
        return [
            'client_name'     => ['required', 'string', 'min:1', 'max:100'],
            'redirect_uris'   => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if (!$parts || empty($parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                    $fail('Redirect URI noto‘g‘ri.');

                    return;
                }
                $https    = ($parts['scheme'] ?? '') === 'https';
                $loopback = ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['127.0.0.1', '[::1]', 'localhost'], true);
                if (!$https && !$loopback) {
                    $fail('HTTPS yoki localhost redirect URI kerak.');
                }
            }],
            'token_endpoint_auth_method' => ['sometimes', Rule::in(['none'])],
            'grant_types'                => ['sometimes', 'array'],
            'grant_types.*'              => [Rule::in(['authorization_code', 'refresh_token'])],
            'response_types'             => ['sometimes', 'array'],
            'response_types.*'           => [Rule::in(['code'])],
        ];
    }

    public function messages(): array
    {
        return ['client_name.required' => 'Ilova nomi kerak.', 'redirect_uris.required' => 'Redirect URI kerak.'];
    }
}
