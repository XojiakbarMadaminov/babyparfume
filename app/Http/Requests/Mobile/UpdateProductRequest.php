<?php

namespace App\Http\Requests\Mobile;

use App\Models\Stock;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Services\ProductPhotoService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['stocks', 'sizes'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => json_decode($this->input($field), true)]);
            }
        }
    }

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    public function rules(): array
    {
        $stockIds = Stock::query()->pluck('id')->all();
        $type     = $this->input('type', $this->route('product')->type);

        return [
            'photo'                     => ProductPhotoService::rules(),
            'remove_image'              => ['sometimes', 'boolean'],
            'store_id'                  => ['required', 'integer', Rule::in([$this->user()->current_store_id])],
            'name'                      => ['required', 'string', 'max:255'],
            'barcode'                   => ['required', 'string', 'regex:/^[0-9]+$/', 'max:100', Rule::unique('products', 'barcode')->where('store_id', $this->user()->current_store_id)->ignore($this->route('product'))],
            'initial_price'             => ['required', 'integer', 'min:0', 'max:999999999999'],
            'price'                     => ['required', 'integer', 'min:0', 'max:999999999999'],
            'type'                      => ['required_with:stocks,sizes', Rule::in([Product::TYPE_PACKAGE, Product::TYPE_SIZE])],
            'stocks'                    => [Rule::excludeIf($type !== Product::TYPE_PACKAGE), 'present_if:type,package', 'array', 'max:100'],
            'stocks.*'                  => ['array:stock_id,quantity'],
            'stocks.*.stock_id'         => ['required', 'integer', 'distinct', Rule::in($stockIds)],
            'stocks.*.quantity'         => ['required', 'integer', 'min:0', 'max:2147483647'],
            'sizes'                     => [Rule::excludeIf($type !== Product::TYPE_SIZE), Rule::requiredIf($this->has('type') && $type === Product::TYPE_SIZE), 'array', 'min:1', 'max:50'],
            'sizes.*'                   => ['array:id,size,stocks'],
            'sizes.*.id'                => ['nullable', 'integer', 'distinct', Rule::exists('product_sizes', 'id')->where('product_id', $this->route('product')->id)],
            'sizes.*.size'              => ['required', 'numeric', 'min:0', 'max:999999999', 'distinct'],
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
            'barcode.required'             => 'Shtrix-kodni kiriting yoki skanerlang.',
            'barcode.regex'                => 'Shtrix-kod faqat raqamlardan iborat bo‘lsin.',
            'barcode.unique'               => 'Bu shtrix-kod ushbu do‘konda mavjud.',
            'type.in'                      => 'Mahsulot turini tanlang.',
            'stocks.*.stock_id.in'         => 'Faqat joriy do‘kon omborini tanlang.',
            'sizes.*.stocks.*.stock_id.in' => 'Faqat joriy do‘kon omborini tanlang.',
            'sizes.*.id.exists'            => 'Razmer ushbu mahsulotga tegishli emas.',
            'sizes.*.size.distinct'        => 'Razmerlar takrorlanmasin.',
            '*.min'                        => 'Qiymat manfiy bo‘lmasin.',
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
