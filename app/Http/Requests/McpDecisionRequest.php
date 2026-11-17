<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class McpDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['confirmation' => ['accepted']];
    }

    public function messages(): array
    {
        return ['confirmation.accepted' => 'Amalni tasdiqlang.'];
    }
}
