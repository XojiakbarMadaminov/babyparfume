<?php

namespace App\Http\Requests\Flutter;

use App\Models\Debtor;
use App\Services\DebtorPhotoService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;

class CreateDebtorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', Debtor::class);
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $digits = preg_replace('/\D/', '', $phone);
            $this->merge(['phone' => '+' . (strlen($digits) === 9 ? '998' . $digits : $digits)]);
        }
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'full_name'  => ['required', 'string', 'max:255'],
            'phone'      => ['required', 'string', 'regex:/^\+998[0-9]{9}$/'],
            'amount'     => ['required', 'integer', 'min:1', 'max:999999999999'],
            'date'       => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note'       => ['nullable', 'string', 'max:500'],
            'photo'      => DebtorPhotoService::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required'  => 'So‘rov identifikatori yo‘q.',
            'request_id.uuid'      => 'So‘rov identifikatori yaroqli UUID bo‘lishi kerak.',
            'full_name.required'   => 'Mijozning ismini kiriting.',
            'phone.regex'          => 'Telefonni +998 va 9 ta raqam ko‘rinishida kiriting.',
            'amount.min'           => 'Qarz summasi noldan katta bo‘lsin.',
            'amount.integer'       => 'Summani butun son bilan kiriting.',
            'date.before_or_equal' => 'Qarz sanasi kelajakda bo‘lishi mumkin emas.',
            'photo.image'          => 'Haqiqiy rasm faylini tanlang.',
            'photo.mimes'          => 'JPG, PNG yoki WebP rasmini tanlang.',
            'photo.max'            => 'Rasm 10 MB dan oshmasin.',
        ];
    }
}
