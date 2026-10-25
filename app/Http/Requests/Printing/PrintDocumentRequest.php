<?php

namespace App\Http\Requests\Printing;

use Illuminate\Foundation\Http\FormRequest;

class PrintDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('printing.enabled');
    }

    public function rules(): array
    {
        return [
            'document_type'                    => ['required', 'in:receipt,barcode'], 'paper_profile_id' => ['required', 'uuid'],
            'paper_profile_version'            => ['required', 'integer', 'min:1'],
            'copies'                           => ['exclude_unless:document_type,receipt', 'required', 'integer', 'min:1', 'max:100'],
            'items'                            => ['exclude_unless:document_type,barcode', 'required', 'array', 'min:1', 'max:100'],
            'items.*'                          => ['array:product_id,copies'], 'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.copies'                   => ['required', 'integer', 'min:1', 'max:1000'],
            'source'                           => ['exclude_unless:document_type,receipt', 'required', 'array:kind,sale_id,local_sale_id,operation_id,snapshot'],
            'source.kind'                      => ['required_with:source', 'in:server_sale,local_sale'],
            'source.sale_id'                   => ['exclude_unless:source.kind,server_sale', 'required', 'integer', 'min:1'],
            'source.local_sale_id'             => ['required_if:source.kind,local_sale', 'nullable', 'uuid'],
            'source.operation_id'              => ['required_if:source.kind,local_sale', 'nullable', 'uuid'],
            'source.snapshot'                  => ['exclude_unless:source.kind,local_sale', 'required', 'array:date,client_name,items,total_amount,paid_amount,remaining_amount,receipt_discount,payment_type,cash,card,received_cash,change'],
            'source.snapshot.date'             => ['required_with:source.snapshot', 'date'],
            'source.snapshot.client_name'      => ['nullable', 'string', 'max:255'],
            'source.snapshot.payment_type'     => ['required_with:source.snapshot', 'in:cash,card,debt,transfer,mixed,partial,preorder'],
            'source.snapshot.items'            => ['required_with:source.snapshot', 'array', 'min:1', 'max:200'],
            'source.snapshot.items.*'          => ['array:name,size,qty,price,discount'],
            'source.snapshot.items.*.name'     => ['required', 'string', 'max:255'],
            'source.snapshot.items.*.size'     => ['nullable', 'string', 'max:100'],
            'source.snapshot.items.*.qty'      => ['required', 'integer', 'min:1', 'max:2147483647'],
            'source.snapshot.items.*.price'    => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.items.*.discount' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.total_amount'     => ['required_with:source.snapshot', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.paid_amount'      => ['required_with:source.snapshot', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.remaining_amount' => ['required_with:source.snapshot', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.receipt_discount' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.cash'             => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.card'             => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.received_cash'    => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'source.snapshot.change'           => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return ['*.required' => 'Majburiy maydonni kiriting.', '*.uuid' => 'Identifikator yaroqsiz.',
            '*.integer'      => 'Butun son kiriting.', '*.min' => 'Qiymat ruxsat etilgan chegaradan kichik.',
            '*.max'          => 'Qiymat ruxsat etilgan chegaradan katta.', '*.in' => 'Tanlangan qiymat yaroqsiz.'];
    }
}
