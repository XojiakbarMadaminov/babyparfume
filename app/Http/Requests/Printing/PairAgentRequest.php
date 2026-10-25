<?php

namespace App\Http\Requests\Printing;

use Illuminate\Foundation\Http\FormRequest;

class PairAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'size:12', 'regex:/^[A-Fa-f0-9]+$/D'],
            'name'     => ['required', 'string', 'max:100'], 'version' => ['required', 'string', 'max:50']];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
