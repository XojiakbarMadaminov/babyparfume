<?php

namespace App\Services\Printing;

use App\Models\User;
use App\Models\Store;
use App\Models\PrintJob;
use App\Models\PrintAgent;
use Illuminate\Support\Str;
use App\Models\PrintProfile;
use App\Models\PrintDocument;
use App\Models\PrintJobEvent;
use App\Models\PrintStoreSetting;
use Illuminate\Support\Facades\Storage;

class PrintJobService
{
    public function __construct(private PrintDocumentService $documents) {}

    public function create(User $user, Store $store, ?int $deviceId, array $data, ?PrintJob $original = null): array
    {
        $hash = hash('sha256', json_encode(['request' => $this->canonical($data), 'reprint_of' => $original?->id], JSON_THROW_ON_ERROR));

        return $store->getConnection()->transaction(function () use ($user, $store, $deviceId, $data, $hash, $original): array {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existing = PrintJob::query()->where('client_job_id', $data['client_job_id'])->first();
            if ($existing) {
                abort_unless($existing->store_id === $store->id && $existing->user_id === $user->id
                    && $existing->device_id === $deviceId && hash_equals($existing->payload_hash, $hash), 409, 'idempotency_mismatch');
                $this->authorizeJob($user, $store, $existing);

                return ['job' => $existing, 'replay' => true];
            }
            $requested = \Illuminate\Support\Carbon::parse($data['requested_at']);
            $until     = $requested->copy()->addSeconds(config('printing.job_ttl_seconds'));
            abort_if($until->lte(now()) || $requested->gt(now()->addMinutes(5)), 422, 'Chop etish muddati tugagan yoki qurilma soati mos emas. Yangi tasdiq kerak.');
            if ($until->gt(now()->addSeconds(config('printing.job_ttl_seconds')))) {
                $until = now()->addSeconds(config('printing.job_ttl_seconds'));
            }
            if ($original) {
                $this->authorizeJob($user, $store, $original);
                PrintAccess::authorize($user, $store->id, $original->document->document_type === 'receipt' ? 'reprint_receipts' : 'print_barcodes');
                $made     = $this->documents->copy($user, $store, $deviceId, $original->document);
                $document = $made['document'];
            } elseif (!empty($data['document_token'])) {
                $document = PrintDocument::query()->where('token_hash', hash('sha256', $data['document_token']))
                    ->where('store_id', $store->id)->where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
                abort_if($document->expires_at->isPast(), 422, 'Preview muddati tugagan. Qayta oching.');
                $this->documents->profile($user, $store, ['document_type' => $document->document_type,
                    'paper_profile_id'                                    => $document->profile_id, 'paper_profile_version' => $document->profile_snapshot['version'], 'trigger' => str_starts_with((string) $document->source_key, 'test:') ? 'automatic' : null], str_starts_with((string) $document->source_key, 'test:'));
            } else {
                abort_unless(($data['trigger'] ?? null) === 'automatic' && $data['document_type'] === 'receipt'
                    && PrintStoreSetting::query()->where('store_id', $store->id)->value('auto_print_receipt'), 403, 'Avtomatik chek o‘chirilgan.');
                $profile = PrintProfile::query()->where('store_id', $store->id)->where('document_type', 'receipt')->where('is_default', true)->where('active', true)->firstOrFail();
                abort_unless($profile->id === $data['paper_profile_id'] && $profile->default_copies === (int) $data['copies'], 422, 'Standart chek profilidan foydalaning.');
                $made     = $this->documents->create($user, $store, $deviceId, $data);
                $document = $made['document'];
            }
            $automaticKey = ($data['trigger'] ?? null) === 'automatic' ? $store->id . ':' . $document->source_key : null;
            if ($automaticKey) {
                $prior = PrintJob::query()->where('automatic_key', $automaticKey)->first();
                if ($prior) {
                    Storage::disk('local')->delete($document->path);
                    $document->delete();

                    return ['job' => $prior, 'replay' => true];
                }
            }
            $p   = $document->profile_snapshot;
            $job = PrintJob::query()->create(['client_job_id' => $data['client_job_id'], 'store_id' => $store->id,
                'user_id'                                     => $user->id, 'device_id' => $deviceId, 'document_id' => $document->id,
                'agent_id'                                    => $p['agent_id'], 'printer_id' => $p['printer_id'], 'payload_hash' => $hash,
                'automatic_key'                               => $automaticKey, 'auto_submit_until' => $until,
                'reprint_of'                                  => $original?->id, 'reprint_reason' => $data['reason'] ?? null]);

            return ['job' => $job, 'replay' => false];
        }, 3);
    }

    public function authorizeJob(User $user, Store $store, PrintJob $job): void
    {
        abort_unless($job->store_id === $store->id, 404);
        PrintAccess::authorize($user, $store->id, $this->permission($job->document));
    }

    public function cancel(User $user, Store $store, PrintJob $job): void
    {
        $this->authorizeJob($user, $store, $job);
        $job->getConnection()->transaction(function () use ($job): void {
            $job = PrintJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless(in_array($job->status, ['queued', 'claimed', 'rendering', 'failed_before_submission']), 409, 'Printerga yuborilgan topshiriqni shu yerdan bekor qilib bo‘lmaydi.');
            $job->update(['status' => 'cancelled']);
        });
    }

    public function expire(int $storeId): void
    {
        PrintJob::query()->where('store_id', $storeId)->whereIn('status', ['queued', 'failed_before_submission'])
            ->where('auto_submit_until', '<=', now())->update(['status' => 'expired', 'error_code' => 'job_expired']);
        PrintJob::query()->where('store_id', $storeId)->whereIn('status', ['claimed', 'rendering', 'submitting'])
            ->where('claim_expires_at', '<=', now())->update(['status' => 'unknown', 'error_code' => 'submission_unknown']);
    }

    public function claim(PrintAgent $actor): ?PrintJob
    {
        return $actor->getConnection()->transaction(function () use ($actor): ?PrintJob {
            Store::query()->whereKey($actor->store_id)->lockForUpdate()->firstOrFail();
            $agent = PrintAgent::query()->whereNull('revoked_at')->lockForUpdate()->findOrFail($actor->id);
            $this->expire($agent->store_id);
            $candidates = PrintJob::query()->where('agent_id', $agent->id)->whereIn('status', ['queued', 'failed_before_submission'])
                ->where('auto_submit_until', '>', now())
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('created_at')->with(['document' => fn ($query) => $query
                ->select(['id', 'profile_id', 'profile_snapshot', 'document_type', 'source_key', 'copies', 'total_labels', 'sha256'])
                ->with('profile'), 'printer'])->lockForUpdate()->get();
            foreach ($candidates as $job) {
                $creator    = User::query()->find($job->user_id);
                $permission = $this->permission($job->document);
                if (!$creator || !PrintAccess::allows($creator, $job->store_id, $permission)
                    || ($job->reprint_of && $job->document->document_type === 'receipt' && !PrintAccess::allows($creator, $job->store_id, 'reprint_receipts'))) {
                    $job->update(['status' => 'cancelled', 'error_code' => 'permission_denied']);
                    continue;
                }
                $error = $this->routeError($job);
                if ($error) {
                    $job->update(['error_code' => $error]);
                    continue;
                }
                $job->update(['status' => 'claimed', 'claim_token' => (string) Str::uuid(),
                    'claim_expires_at' => now()->addSeconds(90), 'error_code' => null]);

                return $job->fresh(['document', 'printer']);
            }

            return null;
        }, 3);
    }

    public function event(PrintAgent $actor, string $jobId, array $data): PrintJob
    {
        return $actor->getConnection()->transaction(function () use ($actor, $jobId, $data): PrintJob {
            Store::query()->whereKey($actor->store_id)->lockForUpdate()->firstOrFail();
            $job = PrintJob::query()->where('agent_id', $actor->id)->lockForUpdate()->findOrFail($jobId);
            abort_unless($job->claim_token && hash_equals($job->claim_token, $data['claim_token']), 409, 'claim_mismatch');
            $old = PrintJobEvent::query()->where('event_id', $data['event_id'])->first();
            if ($old) {
                abort_unless($old->job_id === $job->id && $old->data === $data, 409, 'event_mismatch');

                return $job;
            }
            $next        = $data['status'];
            $transitions = [
                'claimed'    => ['rendering', 'failed_before_submission', 'unknown'],
                'rendering'  => ['submitting', 'failed_before_submission', 'unknown'],
                'submitting' => ['spooled', 'unknown'],
                'spooled'    => ['completed_reported', 'unknown'],
                'unknown'    => ['spooled', 'completed_reported', 'unknown'],
            ];
            abort_unless(in_array($next, $transitions[$job->status] ?? [], true), 409, 'job_state_changed');
            if ($next === 'submitting') {
                abort_if($job->auto_submit_until->isPast(), 409, 'job_expired');
                abort_if($this->routeError($job), 409, 'profile_stale');
                $creator = User::query()->findOrFail($job->user_id);
                PrintAccess::authorize($creator, $job->store_id, $this->permission($job->document));
                if ($job->reprint_of && $job->document->document_type === 'receipt') {
                    PrintAccess::authorize($creator, $job->store_id, 'reprint_receipts');
                }
            }
            abort_if($next === 'spooled' && empty($data['windows_job_id']), 422, 'windows_job_id_required');
            PrintJobEvent::query()->create(['job_id' => $job->id, 'event_id' => $data['event_id'], 'status' => $next, 'data' => $data]);
            $job->update(['status' => $next, 'windows_job_id' => $data['windows_job_id'] ?? $job->windows_job_id,
                'error_code'       => $data['error_code'] ?? null, 'message' => $data['message'] ?? null,
                'claim_expires_at' => now()->addMinutes(10), 'next_attempt_at' => $next === 'failed_before_submission' ? now()->addSeconds(15) : null]);

            return $job;
        }, 3);
    }

    public function present(PrintJob $job): array
    {
        $d = $job->document;

        return ['id'            => $job->id, 'client_job_id' => $job->client_job_id, 'status' => $job->status,
            'document_type'     => $d->document_type, 'document_id' => $d->id,
            'copies'            => $d->copies, 'total_labels' => $d->total_labels,
            'profile_name'      => $d->profile_snapshot['name'], 'printer_name' => $d->profile_snapshot['printer_name'],
            'auto_submit_until' => $job->auto_submit_until->toIso8601String(),
            'error_code'        => $job->error_code, 'message' => $job->message, 'reprint_of' => $job->reprint_of,
            'created_at'        => $job->created_at->toIso8601String()];
    }

    private function permission(PrintDocument $document): string
    {
        return str_starts_with((string) $document->source_key, 'test:') ? 'test_printing' : ($document->document_type === 'receipt' ? 'print_receipts' : 'print_barcodes');
    }

    private function routeError(PrintJob $job): ?string
    {
        $p       = $job->document->profile;
        $printer = $job->printer;
        if (!$p->active || $p->version !== $job->document->profile_snapshot['version'] || $p->printer_id !== $printer->id) {
            return 'profile_stale';
        }
        if (!$printer->available || $printer->agent_id !== $job->agent_id) {
            return 'printer_not_found';
        }
        if ($printer->loaded_media !== $job->document->document_type) {
            return 'media_mismatch';
        }

        return null;
    }

    private function canonical(array $data): array
    {
        if (!array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
