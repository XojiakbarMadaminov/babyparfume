<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class DebtorTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id'     => ['required', 'uuid'],
            'store_id'       => ['required', 'integer'],
            'type'           => ['required', Rule::in(['debt', 'payment'])],
            'amount'         => ['required', 'integer', 'min:1', 'max:999999999999'],
            'note'           => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card'])],
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required' => 'So‘rov identifikatori yo‘q. Sahifani yangilang.',
            'store_id.required'   => 'Do‘konni tanlang.',
            'type.in'             => 'Operatsiya turi noto‘g‘ri.',
            'amount.min'          => 'Summa noldan katta bo‘lsin.',
            'amount.integer'      => 'Summani butun son bilan kiriting.',
            'note.max'            => 'Izoh 500 belgidan oshmasin.',
        ];
    }
}
