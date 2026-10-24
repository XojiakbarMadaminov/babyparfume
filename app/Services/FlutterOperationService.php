<?php

namespace App\Services;

use App\Models\User;

use App\Models\Store;
use App\Models\Debtor;
use App\Support\PhoneNumber;
use App\Models\FlutterDevice;
use Illuminate\Validation\Rule;
use App\Models\FlutterSyncConflict;
use App\Models\FlutterSyncOperation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

class FlutterOperationService
{
    public function __construct(private FlutterDebtService $debts, private FlutterSaleService $sales, private FlutterSyncService $sync) {}

    public static function canResolve(User $user, Store $store): bool
    {
        return (bool) config('flutter.enabled') && (bool) config('flutter.conflicts_enabled')
            && ($user->isSuperAdmin() || $user->can('resolve_flutter_sync_conflicts'))
            && $user->stores()->whereKey($store->id)->exists();
    }

    public function resolve(User $administrator, Store $store, int $conflictId, string $decision, string $note): array
    {
        abort_unless(self::canResolve($administrator, $store), 403);
        Validator::make(['decision' => $decision, 'note' => $note], [
            'decision' => ['required', Rule::in(['apply', 'reject'])], 'note' => ['required', 'string', 'max:500'],
        ])->validate();

        return $store->getConnection()->transaction(function () use ($administrator, $store, $conflictId, $decision, $note): array {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $conflict  = FlutterSyncConflict::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($conflictId);
            $operation = FlutterSyncOperation::query()->where('operation_id', $conflict->operation_id)->lockForUpdate()->firstOrFail();
            if ($conflict->status === 'resolved') {
                abort_unless($conflict->resolution === ($decision === 'apply' ? 'applied' : 'rejected'), 409, 'Bu ziddiyat boshqa qaror bilan hal qilingan.');

                return $this->result($operation, true);
            }
            $before = $conflict->server_state;
            if ($decision === 'apply') {
                $actor = $operation->user;
                abort_unless($actor && $actor->stores()->whereKey($store->id)->exists(), 403);
                $result = $this->apply($actor, $store, $operation->device, $operation, ['entity_id' => $operation->entity_id], true);
                $operation->update(['status' => 'applied', 'entity_id' => $result['canonical_id'],
                    'response_data'          => $result['response_data'], 'applied_at' => now(), 'resolution' => 'applied']);
            } else {
                $operation->update(['status' => 'rejected', 'resolution' => 'rejected', 'response_data' => ['error' => 'Administrator rad etdi.', 'note' => $note]]);
            }
            $conflict->update(['status' => 'resolved', 'resolution' => $operation->resolution,
                'resolved_by'           => $administrator->id, 'resolved_at' => now(),
                'server_state'          => ['before' => $before, 'resolution_note' => $note]]);

            return $this->result($operation, false);
        }, 3);
    }

    /** @param array<string, mixed> $envelope */
    public function process(User $user, Store $store, FlutterDevice $device, array $envelope): array
    {
        $hash = $this->hashEnvelope($envelope);

        try {
            return $store->getConnection()->transaction(function () use ($user, $store, $device, $envelope, $hash): array {
                Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
                $existing = FlutterSyncOperation::query()->where('operation_id', $envelope['operation_id'])->first();
                if ($existing) {
                    return $this->replay($existing, $user, $store, $device, $hash);
                }
                foreach ($envelope['depends_on'] ?? [] as $parentId) {
                    $parent = FlutterSyncOperation::query()->where('operation_id', $parentId)->where('store_id', $store->id)
                        ->where('user_id', $user->id)->where('device_id', $device->id)->where('status', 'applied')->first();
                    if (!$parent) {
                        return ['operation_id' => $envelope['operation_id'], 'status' => 'blocked', 'error' => 'dependency_pending'];
                    }
                }
                $operation = FlutterSyncOperation::query()->create([
                    'operation_id'  => $envelope['operation_id'], 'device_id' => $device->id,
                    'user_id'       => $user->id, 'store_id' => $store->id,
                    'entity_type'   => $envelope['entity_type'], 'entity_id' => $envelope['entity_id'] ?? null,
                    'local_id'      => $envelope['local_id'] ?? null, 'operation_type' => $envelope['operation_type'],
                    'base_revision' => $envelope['base_revision'] ?? null, 'payload_hash' => $hash,
                    'payload'       => $envelope['payload'], 'occurred_at' => $envelope['occurred_at'] ?? now(), 'status' => 'pending',
                ]);

                try {
                    $result = $store->getConnection()->transaction(fn (): array => $this->apply($user, $store, $device, $operation, $envelope));
                    $operation->update(['entity_id' => $result['canonical_id'], 'status' => 'applied',
                        'response_data'             => $result['response_data'], 'applied_at' => now()]);
                } catch (AuthorizationException $exception) {
                    $operation->update(['status' => 'rejected', 'response_data' => ['error' => 'permission_denied']]);
                } catch (ValidationException|ModelNotFoundException $exception) {
                    $reason = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : 'Yozuv ushbu do‘konda topilmadi.';
                    $operation->update(['status' => 'conflict', 'response_data' => ['error' => $reason]]);
                    FlutterSyncConflict::query()->create([
                        'operation_id' => $operation->operation_id, 'store_id' => $store->id, 'user_id' => $user->id,
                        'entity_type'  => $operation->entity_type, 'entity_id' => $operation->entity_id,
                        'reason'       => $reason, 'client_state' => $operation->payload, 'status' => 'pending',
                        'server_state' => $operation->entity_type === 'debtor' && ctype_digit((string) $operation->entity_id)
                            ? Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->find($operation->entity_id)?->only(['id', 'amount', 'date', 'note']) : null,
                    ]);
                }

                return $this->result($operation, false);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = FlutterSyncOperation::query()->where('operation_id', $envelope['operation_id'])->first();
            if (!$existing) {
                throw $exception;
            }

            return $this->replay($existing, $user, $store, $device, $hash);
        }
    }

    private function replay(FlutterSyncOperation $operation, User $user, Store $store, FlutterDevice $device, string $hash): array
    {
        if ((int) $operation->user_id !== $user->id || (int) $operation->store_id !== $store->id
            || (int) $operation->device_id !== $device->id || !hash_equals($operation->payload_hash, $hash)) {
            return ['operation_id' => $operation->operation_id, 'status' => 'conflict', 'error' => 'idempotency_mismatch'];
        }

        return $this->result($operation, true);
    }

    private function result(FlutterSyncOperation $operation, bool $replay): array
    {
        return ['operation_id' => $operation->operation_id, 'status' => $operation->status,
            'canonical_id'     => $operation->entity_id, 'local_id' => $operation->local_id,
            'response_data'    => $operation->response_data, 'error' => $operation->response_data['error'] ?? null, 'is_replay' => $replay];
    }

    private function apply(User $user, Store $store, FlutterDevice $device, FlutterSyncOperation $operation, array $envelope, bool $administratorReview = false): array
    {
        $payload = $operation->payload;
        if ($operation->operation_type === 'client.create') {
            Gate::forUser($user)->authorize('View:Pos');
            if ($operation->entity_type !== 'client') {
                throw ValidationException::withMessages(['entity_type' => 'Amal va yozuv turi mos emas.']);
            }
            $phone = PhoneNumber::forStorage((string) ($payload['phone'] ?? ''));
            Validator::make(['full_name' => $payload['full_name'] ?? null, 'phone' => $phone], [
                'full_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'regex:' . PhoneNumber::PATTERN],
            ])->validate();
            if ($user->getConnection()->getDriverName() === 'pgsql') {
                $user->getConnection()->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$phone]);
            }
            $client = \App\Models\Client::withTrashed()->whereIn('phone', PhoneNumber::aliases($phone))->lockForUpdate()->first();
            if ($client && !$administratorReview) {
                throw ValidationException::withMessages(['phone' => 'Bu telefonli mijoz mavjud. Administrator bog‘lash qarorini berishi kerak.']);
            }
            if (!$client) {
                $client = \App\Models\Client::query()->create(['full_name' => $payload['full_name'], 'phone' => $phone]);
            } elseif ($client->trashed()) {
                $client->restore();
            }

            return ['canonical_id' => (string) $client->id, 'response_data' => ['client_id' => (string) $client->id]];
        }
        if ($operation->operation_type === 'sale.create') {
            Gate::forUser($user)->authorize('View:Pos');
            if ($operation->entity_type !== 'sale') {
                throw ValidationException::withMessages(['entity_type' => 'Amal va yozuv turi mos emas.']);
            }
            if (!empty($payload['client']['local_debtor_id'])) {
                $parent                         = $this->mappedDebtor($user, $store, $device, $payload['client']['local_debtor_id']);
                $payload['client']['server_id'] = (string) $parent->client_id;
            }
            if (!empty($payload['client']['local_id'])) {
                $parent = FlutterSyncOperation::query()->where('store_id', $store->id)->where('user_id', $user->id)
                    ->where('device_id', $device->id)->where('entity_type', 'client')->where('local_id', $payload['client']['local_id'])
                    ->where('status', 'applied')->firstOrFail();
                $payload['client']['server_id'] = (string) $parent->entity_id;
            }
            if (!empty($payload['debtor_context'])) {
                $context = $payload['debtor_context'];
                $id      = (string) ($context['id'] ?? '');
                $debtor  = ctype_digit($id)
                    ? Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->lockForUpdate()->findOrFail($id)
                    : $this->mappedDebtor($user, $store, $device, $id);
                if ((string) $debtor->client_id !== (string) ($payload['client']['server_id'] ?? '')) {
                    throw ValidationException::withMessages(['client' => 'Mijoz va qarzdor mos emas.']);
                }
                $expected = $context['base_revision'] ?? null;
                if (!empty($context['base_operation_id'])) {
                    $parent = FlutterSyncOperation::query()->where('operation_id', $context['base_operation_id'])
                        ->where('store_id', $store->id)->where('user_id', $user->id)->where('device_id', $device->id)
                        ->where('status', 'applied')->firstOrFail();
                    $parentDebtor = $parent->response_data['debtor_id'] ?? null;
                    if ((string) $parentDebtor !== (string) $debtor->id) {
                        throw ValidationException::withMessages(['debtor_context' => 'Oldingi amal boshqa qarzdorga tegishli.']);
                    }
                    $expected = $parent->response_data['debtor_revision'] ?? $parent->response_data['revision'] ?? null;
                }
                if (!$administratorReview && ($expected === null || (int) $expected !== $this->sync->entityRevision($store->id, 'debtor', $debtor->id))) {
                    throw ValidationException::withMessages(['debtor_context' => 'Qarzdor boshqa qurilmada o‘zgargan. Butun savdo administrator qarorini kutadi.']);
                }
                $payload['debtor_context']['id'] = (string) $debtor->id;
            }
            $payload['occurred_at'] = $operation->occurred_at->toIso8601String();
            $result                 = $this->sales->createSale($user, $store, $payload);

            return ['canonical_id' => $result['server_sale_id'], 'response_data' => $result];
        }
        if ($operation->entity_type !== 'debtor') {
            throw ValidationException::withMessages(['entity_type' => 'Amal va yozuv turi mos emas.']);
        }
        $payload['request_id'] = $operation->operation_id;
        if ($operation->operation_type === 'debtor.create') {
            Gate::forUser($user)->authorize('create', Debtor::class);
            $payload['date'] ??= $operation->occurred_at->toDateString();
            $payload['phone'] = PhoneNumber::forStorage((string) ($payload['phone'] ?? ''));
            Validator::make($payload, [
                'full_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'regex:' . PhoneNumber::PATTERN],
                'amount'    => ['required', 'numeric', 'min:1', 'max:999999999999', 'multiple_of:1'],
                'date'      => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:500'],
            ])->validate();
            $debtor = $this->debts->create($user, $store, $payload);
        } else {
            $id     = (string) ($envelope['entity_id'] ?? $payload['debtor_id'] ?? '');
            $debtor = ctype_digit($id) ? Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->lockForUpdate()->findOrFail($id)
                : $this->mappedDebtor($user, $store, $device, $id);
            Gate::forUser($user)->authorize('update', $debtor);
            $revision         = $this->sync->entityRevision($store->id, 'debtor', $debtor->id);
            $expectedRevision = $envelope['base_revision'] ?? null;
            if (!empty($envelope['base_operation_id'])) {
                $parent = FlutterSyncOperation::query()->where('operation_id', $envelope['base_operation_id'])
                    ->where('user_id', $user->id)->where('store_id', $store->id)->where('device_id', $device->id)
                    ->where(function ($query) use ($debtor): void {
                        $query->where(fn ($debtors) => $debtors->where('entity_type', 'debtor')->where('entity_id', (string) $debtor->id))
                            ->orWhere(fn ($sales) => $sales->where('entity_type', 'sale')->where('response_data->debtor_id', (string) $debtor->id));
                    })->where('status', 'applied')->firstOrFail();
                $expectedRevision = $parent->response_data['debtor_revision'] ?? $parent->response_data['revision'] ?? null;
            }
            if ($expectedRevision !== null && (int) $expectedRevision !== $revision) {
                throw ValidationException::withMessages(['base_revision' => 'Qarzdor boshqa qurilmada o‘zgargan. Administrator qarori kerak.']);
            }
            if ($operation->operation_type === 'debtor.transact') {
                Validator::make($payload, ['type' => ['required', Rule::in(['debt', 'payment'])],
                    'amount'                      => ['required', 'numeric', 'min:1', 'max:999999999999', 'multiple_of:1'],
                    'payment_method'              => ['nullable', Rule::in(['cash', 'card'])], 'note' => ['nullable', 'string', 'max:500'],
                ])->validate();
                $debtor = $this->debts->transact($user, $store, $debtor, $payload);
            } elseif ($operation->operation_type === 'debtor.photo') {
                Validator::make($payload, [
                    'action'       => ['required', Rule::in(['replace', 'remove'])],
                    'image_base64' => ['required_if:action,replace', 'string', 'max:14000000'],
                ])->validate();
                $data      = ['full_name' => $debtor->client->full_name, 'phone' => $debtor->client->phone, 'remove_photo' => $payload['action'] === 'remove'];
                $temporary = null;

                try {
                    if ($payload['action'] === 'replace') {
                        $bytes = base64_decode($payload['image_base64'], true);
                        if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024) {
                            throw ValidationException::withMessages(['image_base64' => 'Rasm yaroqsiz yoki 10 MB dan katta.']);
                        }
                        $temporary = tmpfile();
                        if ($temporary === false) {
                            throw new \RuntimeException('Rasm uchun vaqtinchalik joy ajratilmadi.');
                        }
                        fwrite($temporary, $bytes);
                        $data['photo'] = new \Illuminate\Http\UploadedFile(stream_get_meta_data($temporary)['uri'], 'photo.jpg', null, null, true);
                        Validator::make($data, ['photo' => DebtorPhotoService::rules()])->validate();
                    }
                    $debtor = $this->debts->update($user, $store, $debtor, $data);
                } finally {
                    if (is_resource($temporary)) {
                        fclose($temporary);
                    }
                }
            } else {
                $payload['phone'] = PhoneNumber::forStorage((string) ($payload['phone'] ?? ''));
                Validator::make($payload, ['full_name' => ['required', 'string', 'max:255'],
                    'phone'                            => ['required', 'regex:' . PhoneNumber::PATTERN],
                    'amount'                           => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'multiple_of:1'],
                    'date'                             => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:500'],
                ])->validate();
                $before     = (int) $debtor->amount;
                $debtor     = $this->debts->update($user, $store, $debtor, $payload);
                $difference = (int) $debtor->amount - $before;
                if ($difference !== 0) {
                    $debtor->transactions()->create(['type' => $difference > 0 ? 'debt' : 'payment', 'amount' => abs($difference),
                        'date'                              => now(), 'note' => 'Qarz summasini tuzatish: ' . ($payload['note'] ?? ''),
                        'mobile_request_id'                 => $operation->operation_id, 'mobile_user_id' => $user->id]);
                }
            }
        }

        return ['canonical_id' => (string) $debtor->id, 'response_data' => [
            'debtor_id' => (string) $debtor->id, 'client_id' => (string) $debtor->client_id, 'amount' => (string) $debtor->amount,
            'revision'  => (string) $this->sync->entityRevision($store->id, 'debtor', $debtor->id)]];
    }

    private function mappedDebtor(User $user, Store $store, FlutterDevice $device, string $localId): Debtor
    {
        $parent = FlutterSyncOperation::query()->where('store_id', $store->id)->where('user_id', $user->id)
            ->where('device_id', $device->id)->where('status', 'applied')->where(function ($query) use ($localId): void {
                $query->where(fn ($debtors) => $debtors->where('entity_type', 'debtor')->where('local_id', $localId)->where('operation_type', 'debtor.create'))
                    ->orWhere(fn ($sales) => $sales->where('entity_type', 'sale')->where('payload->local_debtor_id', $localId));
            })->firstOrFail();

        return Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->lockForUpdate()->findOrFail($parent->response_data['debtor_id'] ?? $parent->entity_id);
    }

    private function hashEnvelope(array $envelope): string
    {
        $identity = array_intersect_key($envelope, array_flip(['entity_type', 'operation_type', 'entity_id', 'local_id', 'base_revision', 'base_operation_id', 'depends_on', 'payload']));

        return hash('sha256', json_encode($this->canonicalize($identity), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
