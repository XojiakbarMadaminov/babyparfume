<?php

namespace App\Http\Requests\Mobile;

use App\Models\Debtor;
use App\Services\DebtorPhotoService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDebtorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $debtor = $this->route('debtor');

        return $debtor instanceof Debtor && ($this->user()?->can('update', $debtor) ?? false);
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
            'store_id'     => ['required', 'integer'],
            'full_name'    => ['required', 'string', 'max:255'],
            'phone'        => ['required', 'string', 'regex:/^\+998[0-9]{9}$/'],
            'amount'       => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'date'         => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note'         => ['nullable', 'string', 'max:500'],
            'photo'        => DebtorPhotoService::rules(),
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required'    => 'Do‘konni tanlang.',
            'full_name.required'   => 'Mijozning ismini kiriting.',
            'phone.regex'          => 'Telefonni +998 va 9 ta raqam ko‘rinishida kiriting.',
            'amount.min'           => 'Qarz summasi manfiy bo‘lmasin.',
            'amount.integer'       => 'Summani butun son bilan kiriting.',
            'date.before_or_equal' => 'Qarz sanasi kelajakda bo‘lishi mumkin emas.',
            'photo.image'          => 'Haqiqiy rasm faylini tanlang.',
            'photo.mimes'          => 'JPG, PNG yoki WebP rasmini tanlang.',
            'photo.max'            => 'Rasm 10 MB dan oshmasin.',
            'photo.dimensions'     => 'Rasm o‘lchami 6000 × 6000 pikseldan oshmasin.',
        ];
    }
}
