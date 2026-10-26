<?php

namespace App\Http\Requests\Flutter;

use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveProductStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = Product::withoutGlobalScope('current_store')
            ->where('store_id', $this->attributes->get('flutter_store')?->id)
            ->find($this->route('product'));

        return $product && Gate::forUser($this->user())->allows('update', $product);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id'      => ['required', 'uuid'],
            'stock_id'        => ['required', 'integer', 'min:1'],
            'product_size_id' => ['nullable', 'integer', 'min:1'],
            'quantity'        => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required'     => 'Kirim so‘rovi identifikatori kerak.',
            'request_id.uuid'         => 'Kirim so‘rovi identifikatori noto‘g‘ri.',
            'stock_id.required'       => 'Omborni tanlang.',
            'stock_id.integer'        => 'Ombor noto‘g‘ri.',
            'stock_id.min'            => 'Ombor noto‘g‘ri.',
            'product_size_id.integer' => 'Razmer noto‘g‘ri.',
            'product_size_id.min'     => 'Razmer noto‘g‘ri.',
            'quantity.required'       => 'Kirim miqdorini kiriting.',
            'quantity.integer'        => 'Miqdor butun son bo‘lishi kerak.',
            'quantity.min'            => 'Miqdor kamida 1 dona bo‘lishi kerak.',
            'quantity.max'            => 'Miqdor juda katta.',
        ];
    }
}
