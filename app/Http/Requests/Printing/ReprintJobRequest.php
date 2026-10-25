<?php

namespace App\Http\Requests\Printing;

use Illuminate\Foundation\Http\FormRequest;

class ReprintJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        return ['client_job_id' => ['required', 'uuid'], 'requested_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
