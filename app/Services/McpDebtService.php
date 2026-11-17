<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\McpOperation;
use App\Support\PhoneNumber;
use App\Models\McpConnection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class McpDebtService
{
    public function __construct(private McpAccess $access, private FlutterDebtService $debts, private McpNotificationService $notifications) {}

    /** @param array<string, mixed> $input */
    public function prepare(McpConnection $connection, User $user, array $input): McpOperation
    {
        abort_unless(config('mcp.writes_enabled'), 403, 'MCP orqali yozish o‘chirilgan.');
        $this->access->permission($connection, $user, 'debts:write');
        $data = Validator::make($input, [
            'request_id'     => ['required', 'uuid'],
            'type'           => ['required', Rule::in(['create', 'debt', 'payment', 'edit'])],
            'store_id'       => ['nullable', 'integer', 'min:1'],
            'debtor_id'      => ['required_unless:type,create', 'nullable', 'integer', 'min:1'],
            'amount'         => ['required', 'integer', 'min:0', 'max:2147483647'],
            'full_name'      => ['required_if:type,create', 'nullable', 'string', 'max:150'],
            'phone'          => ['required_if:type,create', 'nullable', 'string', 'regex:/^\\+?998[0-9]{9}$/'],
            'date'           => ['required_if:type,create', 'nullable', 'date_format:Y-m-d'],
            'note'           => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card'])],
        ], ['amount.required' => 'Summa kerak.', 'phone.regex' => 'Telefonni +998 bilan kiriting.'])->validate();
        $store              = $this->access->store($connection, $user, isset($data['store_id']) ? (int) $data['store_id'] : null);
        $data['store_id']   = $store->id;
        $data['request_id'] = strtolower($data['request_id']);
        ksort($data);

        return $connection->getConnection()->transaction(function () use ($connection, $user, $store, $data): McpOperation {
            $locked = McpConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $this->access->permission($locked, $user, 'debts:write');
            $this->access->store($locked, $user, $store->id);
            $debtor = $data['type'] === 'create' ? null : $this->access->debtor($store, (int) $data['debtor_id']);
            $this->access->authorizeDebt($user, $data['type'], $debtor);
            $existing = $locked->operations()->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->payload === $data, 409, 'Bu request_id boshqa ma’lumot bilan ishlatilgan.');

                return $existing;
            }
            if ($data['type'] !== 'edit' && (int) $data['amount'] <= 0) {
                throw ValidationException::withMessages(['amount' => 'Summa noldan katta bo‘lsin.']);
            }
            if ($data['type'] === 'create') {
                $this->ensureNewDebt($store, $data['phone'], $data['full_name']);
            }
            $this->validateBalance($debtor, $data);

            return $locked->operations()->create([
                'store_id'   => $store->id, 'request_id' => $data['request_id'], 'type' => $data['type'],
                'payload'    => $data, 'fingerprint' => $debtor ? $this->fingerprint($debtor) : null,
                'expires_at' => now()->addMinutes((int) config('mcp.approval_minutes')),
            ]);
        });
    }

    public function apply(User $user, McpOperation $operation): McpOperation
    {
        abort_unless(config('mcp.writes_enabled'), 403);

        return $operation->getConnection()->transaction(function () use ($user, $operation): McpOperation {
            $connection = McpConnection::query()->lockForUpdate()->findOrFail($operation->connection_id);
            $this->access->permission($connection, $user, 'debts:write');
            $store = $this->access->store($connection, $user, (int) $operation->store_id);
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $locked = McpOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status === 'applied') {
                return $locked;
            }
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 409, 'Tasdiq muddati tugagan yoki amal yopilgan.');
            $data   = $locked->payload;
            $debtor = $locked->type === 'create' ? null : Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->lockForUpdate()->with('client')->findOrFail($data['debtor_id']);
            $this->access->authorizeDebt($user, $locked->type, $debtor);
            if ($debtor) {
                abort_unless(hash_equals($locked->fingerprint, $this->fingerprint($debtor)), 409, 'Qarz ma’lumoti o‘zgargan. Yangidan tayyorlang.');
            } else {
                $this->ensureNewDebt($store, $data['phone'], $data['full_name']);
            }
            $this->validateBalance($debtor, $data);
            $before   = $debtor ? (int) $debtor->amount : 0;
            $previous = request()->attributes->get('mcp_operation_id');
            request()->attributes->set('mcp_operation_id', $locked->id);

            try {
                if ($locked->type === 'create') {
                    $debtor = $this->debts->create($user, $store, [...$data, 'request_id' => $locked->id]);
                } elseif ($locked->type === 'edit') {
                    $difference = (int) $data['amount'] - (int) $debtor->amount;
                    if ($difference !== 0) {
                        $debtor = $this->debts->transact($user, $store, $debtor, [
                            'request_id' => $locked->id, 'type' => $difference > 0 ? 'debt' : 'payment',
                            'amount'     => abs($difference), 'note' => 'MCP tuzatish: ' . ($data['note'] ?? ''),
                        ]);
                    }
                    if (array_key_exists('note', $data)) {
                        $debtor->note = $data['note'];
                    }
                    if (!empty($data['date'])) {
                        $debtor->date = $data['date'];
                    }
                    $debtor->save();
                } else {
                    $debtor = $this->debts->transact($user, $store, $debtor, [...$data, 'request_id' => $locked->id]);
                }
                $this->notifications->record($locked, $user, $debtor, $before);
                $locked->forceFill([
                    'status' => 'applied', 'applied_at' => now(),
                    'result' => ['debtor_id' => $debtor->id, 'store_id' => $store->id, 'before' => $before, 'amount' => (int) $debtor->amount, 'currency' => $debtor->currency],
                ])->save();
            } finally {
                if ($previous === null) {
                    request()->attributes->remove('mcp_operation_id');
                } else {
                    request()->attributes->set('mcp_operation_id', $previous);
                }
            }

            return $locked;
        }, 3);
    }

    public function fingerprint(Debtor $debtor): string
    {
        return hash('sha256', json_encode([
            $debtor->id, $debtor->store_id, $debtor->client_id, (int) $debtor->amount,
            $debtor->currency, (string) $debtor->date, $debtor->note,
            $debtor->client?->full_name, $debtor->client?->phone,
            $debtor->updated_at?->toIso8601String(), $debtor->transactions()->max('id'),
        ], JSON_THROW_ON_ERROR));
    }

    private function ensureNewDebt(Store $store, string $phone, string $fullName): void
    {
        $client = Client::withTrashed()->whereIn('phone', PhoneNumber::aliases($phone))->first();
        if ($client && mb_strtolower(trim($client->full_name)) !== mb_strtolower(trim($fullName))) {
            throw ValidationException::withMessages(['phone' => 'Telefon mavjud mijozga bog‘langan. Ism va telefon mosligini tekshiring.']);
        }
        $exists = Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->where('amount', '>', 0)
            ->whereHas('client', fn ($query) => $query->whereIn('phone', PhoneNumber::aliases($phone)))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['phone' => 'Bu mijozda qarz bor. Mavjud qarzdorni tanlang.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateBalance(?Debtor $debtor, array $data): void
    {
        abort_if($debtor && $debtor->currency !== 'UZS', 422, 'MCP yozish amallari hozir faqat UZS uchun.');
        $amount = (int) $data['amount'];
        if ($debtor && $data['type'] === 'payment' && $amount > (int) $debtor->amount) {
            throw ValidationException::withMessages(['amount' => 'To‘lov qolgan qarzdan oshmasin.']);
        }
        if ($debtor && $data['type'] === 'debt' && $amount + (int) $debtor->amount > 2147483647) {
            throw ValidationException::withMessages(['amount' => 'Jami qarz ruxsat etilgan chegaradan oshadi.']);
        }
    }
}
