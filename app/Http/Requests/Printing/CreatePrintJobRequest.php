<?php

namespace App\Http\Requests\Printing;

class CreatePrintJobRequest extends PrintDocumentRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        $base = ['client_job_id' => ['required', 'uuid'], 'requested_at' => ['required', 'date']];
        if ($this->has('document_token')) {
            return [...$base, 'document_token' => ['required', 'string', 'size:64']];
        }

        return [...parent::rules(), ...$base, 'trigger' => ['required', 'in:automatic']];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
