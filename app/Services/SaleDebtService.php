<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class SaleDebtService
{
    public function record(
        Sale $sale,
        float $remainingAmount,
        ?string $note = null,
        ?int $mobileUserId = null,
        ?string $mobileRequestId = null,
        ?CarbonInterface $occurredAt = null,
        ?int $preferredDebtorId = null,
    ): Debtor {
        if (!$sale->store_id || !$sale->client_id || $remainingAmount <= 0) {
            throw ValidationException::withMessages([
                'sale' => 'Qarz qayd qilish uchun do‘kon, mijoz va qarz summasi kerak.',
            ]);
        }

        return $sale->getConnection()->transaction(function () use ($sale, $remainingAmount, $note, $mobileUserId, $mobileRequestId, $occurredAt, $preferredDebtorId): Debtor {
            Store::query()->whereKey($sale->store_id)->lockForUpdate()->firstOrFail();
            Client::query()->whereKey($sale->client_id)->lockForUpdate()->firstOrFail();

            $debtor = Debtor::withoutGlobalScope('current_store')
                ->where('store_id', $sale->store_id)
                ->where('client_id', $sale->client_id)
                ->whereRaw('UPPER(currency) = ?', ['UZS'])
                ->orderByRaw('CASE WHEN amount > 0 THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredDebtorId ?? 0])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $amount          = (int) round($remainingAmount);
            $date            = $occurredAt ?? now();
            $transactionNote = filled($note) ? $note : "Sotuv #{$sale->id}";

            if ($debtor) {
                $debtor->increment('amount', $amount);
            } else {
                $debtor = Debtor::query()->create([
                    'store_id'  => $sale->store_id,
                    'client_id' => $sale->client_id,
                    'amount'    => $amount,
                    'currency'  => 'UZS',
                    'date'      => $date->toDateString(),
                    'note'      => $transactionNote,
                ]);
            }

            $debtor->transactions()->create([
                'amount'            => $amount,
                'type'              => 'debt',
                'date'              => $date,
                'sale_id'           => $sale->id,
                'note'              => $transactionNote,
                'mobile_user_id'    => $mobileUserId,
                'mobile_request_id' => $mobileRequestId,
            ]);

            return $debtor;
        });
    }
}
