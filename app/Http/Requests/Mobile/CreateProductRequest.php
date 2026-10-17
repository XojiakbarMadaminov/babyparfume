<?php

namespace App\Http\Requests\Mobile;

use App\Models\Stock;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Services\ProductPhotoService;
use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['stocks', 'sizes'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => json_decode($this->input($field), true)]);
            }
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $storeId  = $this->user()->current_store_id;
        $stockIds = Stock::query()->active()->pluck('id')->all();

        return [
            'photo'                     => ProductPhotoService::rules(),
            'store_id'                  => ['required', 'integer', Rule::in([$storeId])],
            'name'                      => ['required', 'string', 'max:255'],
            'barcode'                   => ['required', 'string', 'regex:/^[0-9]+$/', 'max:100', Rule::unique('products', 'barcode')->where('store_id', $storeId)],
            'type'                      => ['required', Rule::in([Product::TYPE_PACKAGE, Product::TYPE_SIZE])],
            'initial_price'             => ['required', 'integer', 'min:0', 'max:999999999999'],
            'price'                     => ['required', 'integer', 'min:0', 'max:999999999999'],
            'stocks'                    => ['exclude_unless:type,package', 'present', 'array', 'max:100'],
            'stocks.*'                  => ['array:stock_id,quantity'],
            'stocks.*.stock_id'         => ['required', 'integer', 'distinct', Rule::in($stockIds)],
            'stocks.*.quantity'         => ['required', 'integer', 'min:0', 'max:2147483647'],
            'sizes'                     => ['exclude_unless:type,size', 'required', 'array', 'min:1', 'max:50'],
            'sizes.*'                   => ['array:size,stocks'],
            'sizes.*.size'              => ['required', 'numeric', 'min:0'],
            'sizes.*.stocks'            => ['present', 'array', 'max:100'],
            'sizes.*.stocks.*'          => ['array:stock_id,quantity'],
            'sizes.*.stocks.*.stock_id' => ['required', 'integer', Rule::in($stockIds)],
            'sizes.*.stocks.*.quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            ...ProductPhotoService::messages(),
            'store_id.in'                  => 'Do‘kon o‘zgargan. Sahifani yangilang.',
            'name.required'                => 'Mahsulot nomini kiriting.',
            'barcode.required'             => 'Shtrix-kodni kiriting, yarating yoki skanerlang.',
            'barcode.regex'                => 'Shtrix-kod faqat raqamlardan iborat bo‘lsin.',
            'barcode.unique'               => 'Bu shtrix-kod ushbu do‘konda mavjud.',
            '*.integer'                    => 'Qiymatni butun son bilan kiriting.',
            'stocks.*.stock_id.in'         => 'Faqat joriy do‘konning faol omborini tanlang.',
            'sizes.*.stocks.*.stock_id.in' => 'Faqat joriy do‘konning faol omborini tanlang.',
            'sizes.required'               => 'Kamida bitta razmer kiriting.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($validator->validated()['sizes'] ?? [] as $index => $size) {
                $stockIds = array_column($size['stocks'], 'stock_id');
                if (count($stockIds) !== count(array_unique($stockIds))) {
                    $validator->errors()->add("sizes.{$index}.stocks", 'Bir razmerda ombor takrorlanmasin.');
                }
            }
        }];
    }
}
