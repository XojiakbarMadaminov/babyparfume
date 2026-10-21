<?php

namespace App\Http\Requests\Flutter;

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
            'filter'  => ['nullable', 'string', 'in:active,closed,overdue'],
            'page'    => ['nullable', 'integer', 'min:1', 'max:10000'],
            'limit'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
