<?php

namespace App\Http\Requests\Printing;

use Illuminate\Foundation\Http\FormRequest;

class AgentHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        return ['version'                          => ['required', 'string', 'max:50'], 'printers' => ['sometimes', 'array', 'max:100'],
            'printers.*'                           => ['array:local_key,name,driver,capabilities'],
            'printers.*.local_key'                 => ['required', 'string', 'size:64', 'distinct', 'regex:/^[a-f0-9]+$/D'],
            'printers.*.name'                      => ['required', 'string', 'max:255'], 'printers.*.driver' => ['nullable', 'string', 'max:255'],
            'printers.*.capabilities'              => ['sometimes', 'array:max_width_mm,dpi'],
            'printers.*.capabilities.max_width_mm' => ['nullable', 'numeric', 'min:10', 'max:1000'],
            'printers.*.capabilities.dpi'          => ['nullable', 'integer', 'min:72', 'max:1200']];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
