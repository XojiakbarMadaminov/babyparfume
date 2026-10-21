<?php

namespace App\Http\Resources\Flutter;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user      = $request->user();
        $canUpdate = $this->client !== null && $user && Gate::forUser($user)->allows('update', $this->resource);

        return [
            'id'                => (string) $this->id,
            'full_name'         => (string) ($this->client?->full_name ?? 'Mijoz o‘chirilgan'),
            'phone'             => $this->client?->phone ? (string) $this->client->phone : null,
            'amount'            => (string) (int) $this->amount,
            'currency'          => (string) ($this->currency ?: 'UZS'),
            'scale'             => 0,
            'date'              => $this->date ? substr((string) $this->date, 0, 10) : null,
            'initial_debt_date' => $this->getInitialDebtDate(),
            'note'              => $this->note ? (string) $this->note : null,
            'photo_url'         => $this->photo_path ? route('flutter.stores.debtors.photo', ['store' => $this->store_id, 'debtor' => $this->id], false) : null,
            'overdue'           => (int) $this->amount > 0 && $this->date && now()->subDays(20)->greaterThan($this->date),
            'can_update'        => $canUpdate,
        ];
    }
}
