<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

class SwitchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['store_id' => ['required', 'integer']];
    }

    public function messages(): array
    {
        return ['store_id.required' => 'Do‘konni tanlang.', 'store_id.integer' => 'Do‘kon noto‘g‘ri tanlangan.'];
    }
}
