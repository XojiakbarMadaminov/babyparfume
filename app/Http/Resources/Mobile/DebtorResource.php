<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $initialDate = $this->getInitialDebtDate();

        return [
            'id'                => $this->id,
            'full_name'         => $this->client?->full_name ?? 'Mijoz o‘chirilgan',
            'phone'             => $this->client?->phone,
            'amount'            => (int) $this->amount,
            'currency'          => $this->currency,
            'date'              => $this->date ? substr((string) $this->date, 0, 10) : null,
            'initial_debt_date' => $initialDate,
            'note'              => $this->note,
            'photo_url'         => $this->when($request->routeIs('mobile.api.debtors.show', 'mobile.api.debtors.update'), fn (): ?string => $this->photo_path ? route('debtors.photo', $this->resource, false) : null),
            'overdue'           => $this->amount > 0 && $this->date && now()->subDays(20)->greaterThan($this->date),
            'can_update'        => $this->client !== null && Gate::allows('update', $this->resource),
        ];
    }
}
