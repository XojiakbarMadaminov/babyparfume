<?php

namespace App\Http\Requests\Flutter;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email'       => ['required', 'email', 'max:255'],
            'password'    => ['required', 'string', 'max:255'],
            'device_uuid' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform'    => ['required', 'in:android,ios,flutter'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'       => 'Emailni kiriting.',
            'email.email'          => 'Email noto‘g‘ri.',
            'email.max'            => 'Email 255 belgidan oshmasin.',
            'password.required'    => 'Parolni kiriting.',
            'password.string'      => 'Parol matn bo‘lishi kerak.',
            'password.max'         => 'Parol 255 belgidan oshmasin.',
            'device_uuid.required' => 'Qurilma identifikatori kerak.',
            'device_uuid.uuid'     => 'Qurilma identifikatori UUID bo‘lishi kerak.',
            'device_name.required' => 'Qurilma nomini kiriting.',
            'device_name.string'   => 'Qurilma nomi matn bo‘lishi kerak.',
            'device_name.max'      => 'Qurilma nomi 100 belgidan oshmasin.',
            'platform.required'    => 'Platformani tanlang.',
            'platform.in'          => 'Platforma noto‘g‘ri.',
        ];
    }
}
