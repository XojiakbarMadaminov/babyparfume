<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\DebtorTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MobileDebtService
{
    public function create(User $user, array $data): Debtor
    {
        $photoPath = null;

        try {
            return $user->getConnection()->transaction(function () use ($user, $data, &$photoPath): Debtor {
                $store = $this->lockStore($user, (int) $data['store_id']);
                $hash  = $this->requestHash('create', $data);
                if ($existing = $this->replay($user, $store, $data['request_id'], $hash)) {
                    return $existing;
                }
                if (($data['photo'] ?? null) instanceof UploadedFile && $photoPath === null) {
                    $photoPath = app(DebtorPhotoService::class)->store($data['photo']);
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
                    'store_id'   => $store->id,
                    'client_id'  => $client->id,
                    'amount'     => $data['amount'],
                    'currency'   => 'UZS',
                    'date'       => $data['date'],
                    'note'       => $data['note'] ?? null,
                    'photo_path' => $photoPath,
                ]);
                $debtor->save();
                $debtor->transactions()->create([
                    'type'              => 'debt', 'amount' => $data['amount'], 'date' => $data['date'] . ' ' . now()->format('H:i:s'),
                    'note'              => $data['note'] ?? 'Dastlabki qarz',
                    'mobile_request_id' => $data['request_id'], 'mobile_request_hash' => $hash, 'mobile_user_id' => $user->id,
                ]);

                return $debtor;
            }, 3);
        } catch (\Throwable $exception) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }
    }

    public function update(User $user, Debtor $debtor, array $data): Debtor
    {
        $photoPath = null;

        try {
            return $user->getConnection()->transaction(function () use ($user, $debtor, $data, &$photoPath): Debtor {
                $store  = $this->lockStore($user, (int) $data['store_id']);
                $locked = Debtor::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($debtor->id);

                if (($data['photo'] ?? null) instanceof UploadedFile) {
                    $photoPath          = app(DebtorPhotoService::class)->store($data['photo']);
                    $oldPhoto           = $locked->photo_path;
                    $locked->photo_path = $photoPath;
                    if ($oldPhoto) {
                        Storage::disk('local')->delete($oldPhoto);
                    }
                } elseif (!empty($data['remove_photo'])) {
                    if ($locked->photo_path) {
                        Storage::disk('local')->delete($locked->photo_path);
                        $locked->photo_path = null;
                    }
                }

                $digits   = ltrim($data['phone'], '+');
                $existing = Client::withTrashed()
                    ->whereIn('phone', [$data['phone'], $digits, substr($digits, -9)])
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->id !== $locked->client_id) {
                    $hasActiveDebt = Debtor::query()
                        ->where('store_id', $store->id)
                        ->where('client_id', $existing->id)
                        ->where('id', '!=', $locked->id)
                        ->where('amount', '>', 0)
                        ->exists();

                    if ($hasActiveDebt) {
                        throw ValidationException::withMessages([
                            'phone' => 'Ushbu telefon raqamli mijoz qarzdorlar ro‘yxatida allaqachon mavjud.',
                        ]);
                    }

                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->update(['full_name' => $data['full_name']]);
                    $locked->client_id = $existing->id;
                } else {
                    if ($locked->client) {
                        $locked->client->update([
                            'full_name' => $data['full_name'],
                            'phone'     => $data['phone'],
                        ]);
                    } else {
                        $client = Client::query()->create([
                            'full_name' => $data['full_name'],
                            'phone'     => $data['phone'],
                        ]);
                        $locked->client_id = $client->id;
                    }
                }

                if (array_key_exists('amount', $data) && $data['amount'] !== null) {
                    $locked->amount = (int) $data['amount'];
                }
                if (!empty($data['date'])) {
                    $locked->date = $data['date'];
                }
                if (array_key_exists('note', $data)) {
                    $locked->note = $data['note'] ?: null;
                }

                $locked->save();

                return $locked;
            }, 3);
        } catch (\Throwable $exception) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }
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
        if (($data['photo'] ?? null) instanceof UploadedFile) {
            $data['photo'] = hash_file('sha256', $data['photo']->getRealPath());
        }
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
