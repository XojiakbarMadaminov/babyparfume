<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q'       => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'filter'  => ['nullable', Rule::in(['active', 'all', 'overdue', 'closed'])],
            'page'    => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return ['q.max' => 'Qidiruv 100 belgidan oshmasin.', 'page.integer' => 'Sahifa raqami noto‘g‘ri.', 'filter.in' => 'Filtr noto‘g‘ri.'];
    }
}
