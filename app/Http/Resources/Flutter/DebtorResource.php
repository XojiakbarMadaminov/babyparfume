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
            'id'           => (string) $this->id,
            'client_id'    => $this->client_id ? (string) $this->client_id : null,
            'revision'     => (string) ($this->sync_revision ?? app(\App\Services\FlutterSyncService::class)->entityRevision((int) $this->store_id, 'debtor', $this->id)),
            'updated_at'   => $this->updated_at?->toIso8601String(),
            'transactions' => $this->whenLoaded('transactions', fn () => $this->transactions->map(fn ($transaction): array => [
                'id'         => (string) $transaction->id, 'debtor_id' => (string) $this->id,
                'type'       => (string) $transaction->type, 'amount' => (string) $transaction->amount,
                'date'       => (string) $transaction->date, 'note' => $transaction->note,
                'request_id' => $transaction->mobile_request_id,
            ])->values()->all()),
            'full_name'         => (string) ($this->client?->full_name ?? 'Mijoz o‘chirilgan'),
            'phone'             => $this->client?->phone ? (string) $this->client->phone : null,
            'amount'            => (string) (int) $this->amount,
            'currency'          => (string) ($this->currency ?: 'UZS'),
            'scale'             => 0,
            'date'              => $this->date ? substr((string) $this->date, 0, 10) : null,
            'initial_debt_date' => $this->getInitialDebtDate(),
            'note'              => $this->note ? (string) $this->note : null,
            'photo_url'         => $this->photo_path ? route('flutter.stores.debtors.photo', [
                'store'  => $this->store_id,
                'debtor' => $this->id,
                'v'      => hash('sha256', $this->photo_path),
            ], false) : null,
            'overdue'    => (int) $this->amount > 0 && $this->date && now()->subDays(20)->greaterThan($this->date),
            'can_update' => $canUpdate,
        ];
    }
}
