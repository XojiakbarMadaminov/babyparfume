<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class McpDefaultStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $this->user() && (int) $connection->user_id === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return ['store_id' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['store_id.required' => 'Do‘kon tanlang.', 'store_id.integer' => 'Do‘kon noto‘g‘ri.'];
    }
}
