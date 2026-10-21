<?php

namespace App\Http\Requests\Flutter;

use Illuminate\Foundation\Http\FormRequest;

class ResolveConflictRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('flutter_store');

        return $store && \App\Services\FlutterOperationService::canResolve($this->user(), $store);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', \Illuminate\Validation\Rule::in(['apply', 'reject'])],
            'note'     => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['decision.in' => 'Qarorni qayta tanlang.', 'note.required' => 'Administrator izohini kiriting.'];
    }
}
