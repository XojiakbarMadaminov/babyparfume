<?php

namespace App\Http\Requests\Flutter;

use App\Models\Stock;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
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

        if (!$product instanceof Product) {
            $productId = (int) $this->route('product');
            $store     = $this->attributes->get('flutter_store');
            $product   = Product::withoutGlobalScope('current_store')
                ->where('store_id', $store?->id)
                ->find($productId);
        }

        return $product && Gate::forUser($this->user())->allows('update', $product);
    }

    public function rules(): array
    {
        $store    = $this->attributes->get('flutter_store');
        $storeId  = (int) ($store?->id ?? $this->route('store'));
        $stockIds = Stock::withoutGlobalScope('current_store')->pluck('id')->all();
        $product  = $this->route('product');
        $type     = $this->input('type', $product instanceof Product ? $product->type : null);

        return [
            'photo'                     => ProductPhotoService::rules(),
            'remove_image'              => ['sometimes', 'boolean'],
            'name'                      => ['required', 'string', 'max:255'],
            'barcode'                   => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'max:100',
                Rule::unique('products', 'barcode')->where('store_id', $storeId)->ignore($product instanceof Product ? $product->id : $product),
            ],
            'initial_price'             => ['required', 'integer', 'min:0', 'max:999999999999'],
            'price'                     => ['required', 'integer', 'min:0', 'max:999999999999'],
            'type'                      => ['required_with:stocks,sizes', Rule::in([Product::TYPE_PACKAGE, Product::TYPE_SIZE])],
            'stocks'                    => [Rule::excludeIf($type !== Product::TYPE_PACKAGE), 'present_if:type,package', 'array', 'max:100'],
            'stocks.*'                  => ['array:stock_id,quantity'],
            'stocks.*.stock_id'         => ['required', 'integer', 'distinct', Rule::in($stockIds)],
            'stocks.*.quantity'         => ['required', 'integer', 'min:0', 'max:2147483647'],
            'sizes'                     => [Rule::excludeIf($type !== Product::TYPE_SIZE), Rule::requiredIf($this->has('type') && $type === Product::TYPE_SIZE), 'array', 'min:1', 'max:50'],
            'sizes.*'                   => ['array:id,size,stocks'],
            'sizes.*.id'                => ['nullable', 'integer', 'distinct'],
            'sizes.*.size'              => ['required', 'string', 'max:255', 'distinct'],
            'sizes.*.stocks'            => ['required', 'array', 'max:100'],
            'sizes.*.stocks.*'          => ['array:stock_id,quantity'],
            'sizes.*.stocks.*.stock_id' => ['required', 'integer', 'distinct', Rule::in($stockIds)],
            'sizes.*.stocks.*.quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'          => 'Mahsulot nomini kiriting.',
            'barcode.required'       => 'Shtrix-kodni kiriting.',
            'barcode.regex'          => 'Shtrix-kod faqat raqamlardan iborat bo‘lsin.',
            'barcode.unique'         => 'Bu shtrix-kod ushbu do‘konda allaqachon mavjud.',
            'initial_price.required' => 'Kelish narxini kiriting.',
            'price.required'         => 'Sotish narxini kiriting.',
        ];
    }
}
