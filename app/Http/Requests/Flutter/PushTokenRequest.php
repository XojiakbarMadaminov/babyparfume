<?php

namespace App\Http\Requests\Flutter;

use Illuminate\Foundation\Http\FormRequest;

class PushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('flutter_device') !== null;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[a-zA-Z0-9_:\\-]+$/']];
    }

    public function messages(): array
    {
        return ['token.required' => 'Push token kerak.', 'token.regex' => 'Push token formati noto‘g‘ri.'];
    }
}
