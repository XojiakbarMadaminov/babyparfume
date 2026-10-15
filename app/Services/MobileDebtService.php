<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\DebtorTransaction;
use Illuminate\Validation\ValidationException;

class MobileDebtService
{
    public function create(User $user, array $data): Debtor
    {
        return $user->getConnection()->transaction(function () use ($user, $data): Debtor {
            $store = $this->lockStore($user, (int) $data['store_id']);
            $hash  = $this->requestHash('create', $data);
            if ($existing = $this->replay($user, $store, $data['request_id'], $hash)) {
                return $existing;
            }
            $digits = ltrim($data['phone'], '+');
            $client = Client::withTrashed()->whereIn('phone', [$data['phone'], $digits, substr($digits, -9)])->lockForUpdate()->first();
            if ($client && Debtor::query()->where('client_id', $client->id)->where('store_id', $store->id)->where('amount', '>', 0)->exists()) {
                throw ValidationException::withMessages(['phone' => 'Bu mijozda qarz bor. Mavjud mijozga qarz qo‘shish amalidan foydalaning.']);
            }
            if (!$client) {
                $client = Client::query()->create(['full_name' => $data['full_name'], 'phone' => $data['phone']]);
            } elseif ($client->trashed()) {
                $client->restore();
            }
            $debtor = new Debtor([
                'store_id'  => $store->id,
                'client_id' => $client->id,
                'amount'    => $data['amount'],
                'currency'  => 'UZS',
                'date'      => $data['date'],
                'note'      => $data['note'] ?? null,
            ]);
            $debtor->save();
            $debtor->transactions()->create([
                'type'              => 'debt', 'amount' => $data['amount'], 'date' => $data['date'] . ' ' . now()->format('H:i:s'),
                'note'              => $data['note'] ?? 'Dastlabki qarz',
                'mobile_request_id' => $data['request_id'], 'mobile_request_hash' => $hash, 'mobile_user_id' => $user->id,
            ]);

            return $debtor;
        }, 3);
    }

    public function transact(User $user, Debtor $debtor, array $data): Debtor
    {
        return $user->getConnection()->transaction(function () use ($user, $debtor, $data): Debtor {
            $store = $this->lockStore($user, (int) $data['store_id']);
            $hash  = $this->requestHash('transaction:' . $debtor->id, $data);
            if ($existing = $this->replay($user, $store, $data['request_id'], $hash)) {
                return $existing;
            }
            $locked = Debtor::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($debtor->id);
            $client = $locked->client;
            if (!$client) {
                throw ValidationException::withMessages(['amount' => 'Avval mijoz ma’lumotlarini tiklang.']);
            }
            $amount = (int) $data['amount'];
            if ($data['type'] === 'payment' && $amount > $locked->amount) {
                throw ValidationException::withMessages(['amount' => 'To‘lov mavjud qarzdan oshmasin. Qolgan qarz: ' . $locked->amount . ' ' . $locked->currency]);
            }
            $note = $data['note'] ?? null;
            if ($data['type'] === 'payment' && !empty($data['payment_method'])) {
                $note = trim(($note ? $note . ' · ' : '') . 'To‘lov usuli: ' . ($data['payment_method'] === 'cash' ? 'Naqd' : 'Karta'));
            }
            $locked->transactions()->create([
                'type'              => $data['type'], 'amount' => $amount, 'date' => now(), 'note' => $note,
                'mobile_request_id' => $data['request_id'], 'mobile_request_hash' => $hash, 'mobile_user_id' => $user->id,
            ]);
            $locked->amount = (int) $locked->amount + ($data['type'] === 'debt' ? $amount : -$amount);
            $locked->save();

            return $locked;
        }, 3);
    }

    private function lockStore(User $user, int $storeId): Store
    {
        $currentUser = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_unless((int) $currentUser->current_store_id === $storeId, 409, 'Do‘kon o‘zgargan. Sahifani yangilang.');
        abort_unless($currentUser->stores()->whereKey($storeId)->exists(), 403);

        return Store::query()->lockForUpdate()->findOrFail($storeId);
    }

    private function requestHash(string $operation, array $data): string
    {
        unset($data['request_id']);
        ksort($data);

        return hash('sha256', $operation . json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(User $user, Store $store, string $requestId, string $hash): ?Debtor
    {
        $transaction = DebtorTransaction::query()->where('mobile_request_id', $requestId)->first();
        if (!$transaction) {
            return null;
        }
        abort_unless((int) $transaction->mobile_user_id === $user->id && hash_equals($transaction->mobile_request_hash, $hash), 409, 'Bu so‘rov boshqa ma’lumot bilan ishlatilgan.');

        return Debtor::query()->where('store_id', $store->id)->findOrFail($transaction->debtor_id);
    }
}
