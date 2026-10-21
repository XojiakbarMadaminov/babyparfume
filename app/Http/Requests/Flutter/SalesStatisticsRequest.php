<?php

namespace App\Http\Requests\Flutter;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SalesStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->can('View:Pos') && $user->can('view_mobile_sales_statistics');
    }

    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['today', 'week', 'month', 'range'])],
            'start'  => ['required', 'date_format:Y-m-d', 'before_or_equal:end'],
            'end'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (Carbon::parse($this->input('start'))->diffInDays(Carbon::parse($this->input('end'))) > 366) {
                $validator->errors()->add('end', 'Davr 366 kundan oshmasin.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'period.in'             => 'Davrni qayta tanlang.',
            'start.date_format'     => 'Boshlanish sanasini tekshiring.',
            'start.before_or_equal' => 'Boshlanish sanasi tugashdan keyin bo‘lmasin.',
            'end.date_format'       => 'Tugash sanasini tekshiring.',
            'end.before_or_equal'   => 'Tugash sanasi bugundan keyin bo‘lmasin.',
        ];
    }
}
