<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class McpConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'auth_token'       => ['required', 'string', 'max:128'],
            'store_ids'        => ['required', 'array', 'min:1', 'max:100'],
            'store_ids.*'      => ['required', 'integer', 'min:1', 'distinct'],
            'default_store_id' => ['required', 'integer', 'min:1'],
            'permissions'      => ['required', 'array', 'min:1'],
            'permissions.*'    => ['required', 'distinct', Rule::in(config('mcp.permissions'))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = array_map('intval', $this->input('store_ids'));
            if ($this->user()->stores()->whereKey($ids)->count() !== count($ids) || !in_array((int) $this->input('default_store_id'), $ids, true)) {
                $validator->errors()->add('store_ids', 'Faqat o‘zingizga ruxsat etilgan do‘konlarni tanlang.');
            }
            if (in_array('debts:write', $this->input('permissions'), true) && !config('mcp.writes_enabled')) {
                $validator->errors()->add('permissions', 'MCP orqali yozish o‘chirilgan.');
            }
        }];
    }

    public function messages(): array
    {
        return ['store_ids.required' => 'Kamida bitta do‘kon tanlang.', 'permissions.required' => 'Kamida bitta ruxsat tanlang.'];
    }
}
