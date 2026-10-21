<?php

namespace App\Http\Requests\Flutter;

use App\Models\Debtor;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;

class DebtorTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $debtor = $this->route('debtor');

        if (!$debtor instanceof Debtor) {
            $debtorId = (int) $this->route('debtor');
            $store    = $this->attributes->get('flutter_store');
            $debtor   = Debtor::withoutGlobalScope('current_store')
                ->where('store_id', $store?->id)
                ->find($debtorId);
        }

        return $debtor && Gate::forUser($this->user())->allows('update', $debtor);
    }

    public function rules(): array
    {
        return [
            'request_id'     => ['required', 'uuid'],
            'type'           => ['required', Rule::in(['debt', 'payment'])],
            'amount'         => ['required', 'integer', 'min:1', 'max:999999999999'],
            'note'           => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card'])],
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required' => 'So‘rov identifikatori yo‘q.',
            'request_id.uuid'     => 'So‘rov identifikatori yaroqli UUID bo‘lishi kerak.',
            'type.in'             => 'Operatsiya turi noto‘g‘ri.',
            'amount.min'          => 'Summa noldan katta bo‘lsin.',
            'amount.integer'      => 'Summani butun son bilan kiriting.',
            'note.max'            => 'Izoh 500 belgidan oshmasin.',
        ];
    }
}
