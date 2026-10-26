<?php

namespace App\Http\Requests\Mobile;

use App\Support\PhoneNumber;

use App\Services\DebtorPhotoService;
use Illuminate\Foundation\Http\FormRequest;

class CreateDebtorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $this->merge(['phone' => PhoneNumber::forStorage($phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'store_id'   => ['required', 'integer'],
            'full_name'  => ['required', 'string', 'max:255'],
            'phone'      => ['required', 'string', 'regex:' . PhoneNumber::PATTERN],
            'amount'     => ['required', 'integer', 'min:1', 'max:999999999999'],
            'date'       => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note'       => ['nullable', 'string', 'max:500'],
            'photo'      => DebtorPhotoService::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required'  => 'So‘rov identifikatori yo‘q. Sahifani yangilang.',
            'store_id.required'    => 'Do‘konni tanlang.',
            'full_name.required'   => 'Mijozning ismini kiriting.',
            'phone.regex'          => 'Telefon raqamini 7–15 ta raqam bilan kiriting.',
            'amount.min'           => 'Qarz summasi noldan katta bo‘lsin.',
            'amount.integer'       => 'Summani butun son bilan kiriting.',
            'date.before_or_equal' => 'Qarz sanasi kelajakda bo‘lishi mumkin emas.',
            'photo.image'          => 'Haqiqiy rasm faylini tanlang.',
            'photo.mimes'          => 'JPG, PNG yoki WebP rasmini tanlang.',
            'photo.max'            => 'Rasm 10 MB dan oshmasin.',
            'photo.dimensions'     => 'Rasm o‘lchami 6000 × 6000 pikseldan oshmasin.',
        ];
    }
}
