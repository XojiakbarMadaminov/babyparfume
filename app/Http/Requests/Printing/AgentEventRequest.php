<?php

namespace App\Http\Requests\Printing;

use Illuminate\Foundation\Http\FormRequest;

class AgentEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        return ['event_id'   => ['required', 'uuid'], 'claim_token' => ['required', 'uuid'],
            'status'         => ['required', 'in:rendering,submitting,spooled,completed_reported,failed_before_submission,unknown'],
            'windows_job_id' => ['nullable', 'integer', 'min:1'], 'error_code' => ['nullable', 'string', 'max:100'],
            'message'        => ['nullable', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
