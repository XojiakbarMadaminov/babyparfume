<?php

namespace App\Http\Requests\Flutter;

use App\Models\Stock;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
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

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', Product::class);
    }

    public function rules(): array
    {
        $store    = $this->attributes->get('flutter_store');
        $storeId  = (int) ($store?->id ?? $this->route('store'));
        $stockIds = Stock::withoutGlobalScope('current_store')->active()->pluck('id')->all();

        return [
            'photo'                     => ProductPhotoService::rules(),
            'name'                      => ['required', 'string', 'max:255'],
            'barcode'                   => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'max:100',
                Rule::unique('products', 'barcode')->where('store_id', $storeId),
            ],
            'type'                      => ['required', Rule::in([Product::TYPE_PACKAGE, Product::TYPE_SIZE])],
            'initial_price'             => ['required', 'integer', 'min:0', 'max:999999999999'],
            'price'                     => ['required', 'integer', 'min:0', 'max:999999999999'],
            'stocks'                    => ['exclude_unless:type,package', 'present', 'array', 'max:100'],
            'stocks.*'                  => ['array:stock_id,quantity'],
            'stocks.*.stock_id'         => ['required', 'integer', 'distinct', Rule::in($stockIds)],
            'stocks.*.quantity'         => ['required', 'integer', 'min:0', 'max:2147483647'],
            'sizes'                     => ['exclude_unless:type,size', 'required_if:type,size', 'array', 'min:1', 'max:50'],
            'sizes.*'                   => ['array:size,stocks'],
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
            'type.in'                => 'Mahsulot turi noto‘g‘ri.',
            'initial_price.required' => 'Kelish narxini kiriting.',
            'initial_price.min'      => 'Kelish narxi manfiy bo‘lmasin.',
            'price.required'         => 'Sotish narxini kiriting.',
            'price.min'              => 'Sotish narxi manfiy bo‘lmasin.',
            'stocks.required'        => 'Ombor qoldiqlarini kiriting.',
            'sizes.required_if'      => 'Kamida bitta razmer qo‘shing.',
            'sizes.min'              => 'Kamida bitta razmer bo‘lishi kerak.',
        ];
    }
}
