<?php

namespace App\Http\Requests\Flutter;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class SyncOperationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schema_version'                 => ['sometimes', 'integer', Rule::in([1])],
            'operations'                     => ['required', 'array', 'min:1', 'max:100'],
            'operations.*'                   => ['required', 'array'],
            'operations.*.operation_id'      => ['required', 'uuid', 'distinct'],
            'operations.*.entity_type'       => ['required', Rule::in(['sale', 'debtor', 'client'])],
            'operations.*.operation_type'    => ['required', Rule::in(['sale.create', 'client.create', 'debtor.create', 'debtor.transact', 'debtor.update', 'debtor.photo'])],
            'operations.*.entity_id'         => ['nullable', 'string', 'max:50'],
            'operations.*.local_id'          => ['nullable', 'uuid'],
            'operations.*.base_revision'     => ['nullable', 'integer', 'min:0'],
            'operations.*.base_operation_id' => ['nullable', 'uuid'],
            'operations.*.depends_on'        => ['sometimes', 'array', 'max:100'],
            'operations.*.depends_on.*'      => ['uuid', 'distinct'],
            'operations.*.occurred_at'       => ['sometimes', 'date', 'before_or_equal:now'],
            'operations.*.payload'           => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'operations.max'                           => 'Bir so‘rovda ko‘pi bilan 100 ta amal yuboring.',
            'operations.*.operation_id.uuid'           => 'Amal identifikatori UUID bo‘lishi kerak.',
            'operations.*.operation_type.in'           => 'Bu amal oflayn sinxronlashda qo‘llab-quvvatlanmaydi.',
            'operations.*.occurred_at.before_or_equal' => 'Amal vaqti kelajakda bo‘lishi mumkin emas.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('operations') && $this->has('operation_id')) {
            $this->merge(['operations' => [$this->all()]]);
        }
    }
}
