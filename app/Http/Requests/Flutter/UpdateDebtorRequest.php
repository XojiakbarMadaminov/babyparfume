<?php

namespace App\Http\Requests\Flutter;

use App\Models\Debtor;

use App\Support\PhoneNumber;
use App\Services\DebtorPhotoService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDebtorRequest extends FormRequest
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
            'full_name'    => ['required', 'string', 'max:255'],
            'phone'        => ['required', 'string', 'regex:' . PhoneNumber::PATTERN],
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
            'full_name.required'   => 'Mijozning ismini kiriting.',
            'phone.regex'          => 'Telefon raqamini 7–15 ta raqam bilan kiriting.',
            'amount.min'           => 'Qarz summasi manfiy bo‘lmasin.',
            'amount.integer'       => 'Summani butun son bilan kiriting.',
            'date.before_or_equal' => 'Qarz sanasi kelajakda bo‘lishi mumkin emas.',
            'photo.image'          => 'Haqiqiy rasm faylini tanlang.',
        ];
    }
}
