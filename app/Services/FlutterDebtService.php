<?php

namespace App\Services;

use App\Models\User;

use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Support\PhoneNumber;
use App\Models\DebtorTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FlutterDebtService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, Store $store, array $data): Debtor
    {
        $photoPath = null;

        try {
            return $user->getConnection()->transaction(function () use ($user, $store, $data, &$photoPath): Debtor {
                $lockedStore = $this->lockStore($user, $store);
                $hash        = $this->requestHash('create', $data);

                if ($existing = $this->replay($user, $lockedStore, $data['request_id'], $hash)) {
                    return $existing;
                }

                if (($data['photo'] ?? null) instanceof UploadedFile && $photoPath === null) {
                    $photoPath = app(DebtorPhotoService::class)->store($data['photo']);
                }

                $client = Client::withTrashed()
                    ->whereIn('phone', PhoneNumber::aliases($data['phone']))
                    ->lockForUpdate()
                    ->first();

                if ($client && Debtor::withoutGlobalScope('current_store')
                    ->where('client_id', $client->id)
                    ->where('store_id', $lockedStore->id)
                    ->where('amount', '>', 0)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'phone' => 'Bu mijozda qarz bor. Mavjud mijozga qarz qo‘shish amalidan foydalaning.',
                    ]);
                }

                if (!$client) {
                    $client = Client::query()->create([
                        'full_name' => $data['full_name'],
                        'phone'     => $data['phone'],
                    ]);
                } elseif ($client->trashed()) {
                    $client->restore();
                }

                $debtor = new Debtor([
                    'store_id'   => $lockedStore->id,
                    'client_id'  => $client->id,
                    'amount'     => (int) $data['amount'],
                    'currency'   => 'UZS',
                    'date'       => $data['date'],
                    'note'       => $data['note'] ?? null,
                    'photo_path' => $photoPath,
                ]);
                $debtor->save();

                $debtor->transactions()->create([
                    'type'                => 'debt',
                    'amount'              => (int) $data['amount'],
                    'date'                => $data['date'] . ' ' . now()->format('H:i:s'),
                    'note'                => $data['note'] ?? 'Dastlabki qarz',
                    'mobile_request_id'   => $data['request_id'],
                    'mobile_request_hash' => $hash,
                    'mobile_user_id'      => $user->id,
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Store $store, Debtor $debtor, array $data): Debtor
    {
        $photoPath = null;

        try {
            return $user->getConnection()->transaction(function () use ($user, $store, $debtor, $data, &$photoPath): Debtor {
                $lockedStore = $this->lockStore($user, $store);
                $locked      = Debtor::withoutGlobalScope('current_store')
                    ->where('store_id', $lockedStore->id)
                    ->lockForUpdate()
                    ->findOrFail($debtor->id);

                if (($data['photo'] ?? null) instanceof UploadedFile) {
                    $photoPath          = app(DebtorPhotoService::class)->store($data['photo']);
                    $oldPhoto           = $locked->photo_path;
                    $locked->photo_path = $photoPath;
                    if ($oldPhoto) {
                        $user->getConnection()->afterCommit(fn () => Storage::disk('local')->delete($oldPhoto));
                    }
                } elseif (!empty($data['remove_photo'])) {
                    if ($locked->photo_path) {
                        $oldPhoto = $locked->photo_path;
                        $user->getConnection()->afterCommit(fn () => Storage::disk('local')->delete($oldPhoto));
                        $locked->photo_path = null;
                    }
                }

                $existing = Client::withTrashed()
                    ->whereIn('phone', PhoneNumber::aliases($data['phone']))
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->id !== $locked->client_id) {
                    $hasActiveDebt = Debtor::withoutGlobalScope('current_store')
                        ->where('store_id', $lockedStore->id)
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function transact(User $user, Store $store, Debtor $debtor, array $data): Debtor
    {
        return $user->getConnection()->transaction(function () use ($user, $store, $debtor, $data): Debtor {
            $lockedStore = $this->lockStore($user, $store);
            $hash        = $this->requestHash('transaction:' . $debtor->id, $data);

            if ($existing = $this->replay($user, $lockedStore, $data['request_id'], $hash)) {
                return $existing;
            }

            $locked = Debtor::withoutGlobalScope('current_store')
                ->where('store_id', $lockedStore->id)
                ->lockForUpdate()
                ->findOrFail($debtor->id);

            $client = $locked->client;
            if (!$client) {
                throw ValidationException::withMessages(['amount' => 'Avval mijoz ma’lumotlarini tiklang.']);
            }

            $amount = (int) $data['amount'];
            if ($data['type'] === 'payment' && $amount > $locked->amount) {
                throw ValidationException::withMessages([
                    'amount' => 'To‘lov mavjud qarzdan oshmasin. Qolgan qarz: ' . $locked->amount . ' ' . $locked->currency,
                ]);
            }

            $note = $data['note'] ?? null;
            if ($data['type'] === 'payment' && !empty($data['payment_method'])) {
                $note = trim(($note ? $note . ' · ' : '') . 'To‘lov usuli: ' . ($data['payment_method'] === 'cash' ? 'Naqd' : 'Karta'));
            }

            $locked->transactions()->create([
                'type'                => $data['type'],
                'amount'              => $amount,
                'date'                => now(),
                'note'                => $note,
                'mobile_request_id'   => $data['request_id'],
                'mobile_request_hash' => $hash,
                'mobile_user_id'      => $user->id,
            ]);

            $locked->amount = (int) $locked->amount + ($data['type'] === 'debt' ? $amount : -$amount);
            $locked->save();

            return $locked;
        }, 3);
    }

    private function lockStore(User $user, Store $store): Store
    {
        abort_unless($user->stores()->whereKey($store->id)->exists(), 403, 'Bu do‘konga ruxsat yo‘q.');

        return Store::query()->lockForUpdate()->findOrFail($store->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
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

        abort_unless(
            (int) $transaction->mobile_user_id === $user->id && hash_equals($transaction->mobile_request_hash, $hash),
            409,
            'Bu so‘rov boshqa ma’lumot bilan ishlatilgan.'
        );

        return Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($transaction->debtor_id);
    }
}
