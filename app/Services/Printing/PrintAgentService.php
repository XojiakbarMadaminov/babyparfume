<?php

namespace App\Services\Printing;

use App\Models\User;
use App\Models\Store;
use App\Models\PrintJob;
use App\Models\PrintAgent;
use Illuminate\Support\Str;
use App\Models\PrintProfile;
use App\Models\PrintPairingCode;

class PrintAgentService
{
    public function issueCode(User $user, Store $store): string
    {
        PrintAccess::authorize($user, $store->id, 'manage_printing');
        $code = strtoupper(bin2hex(random_bytes(6)));
        PrintPairingCode::query()->create(['store_id' => $store->id, 'user_id' => $user->id,
            'code_hash'                               => hash('sha256', $code), 'expires_at' => now()->addMinutes(10)]);

        return $code;
    }

    public function pair(string $code, string $name, string $version): array
    {
        $pair = PrintPairingCode::query()->where('code_hash', hash('sha256', strtoupper(trim($code))))->first();
        abort_unless($pair, 422, 'Ulash kodi yaroqsiz yoki muddati tugagan.');

        return $pair->getConnection()->transaction(function () use ($pair, $name, $version): array {
            Store::query()->whereKey($pair->store_id)->lockForUpdate()->firstOrFail();
            $pair = $pair->newQuery()->lockForUpdate()->findOrFail($pair->id);
            abort_if($pair->used_at || $pair->expires_at->isPast(), 422, 'Ulash kodi yaroqsiz yoki muddati tugagan.');
            PrintAccess::authorize(User::query()->findOrFail($pair->user_id), $pair->store_id, 'manage_printing');
            abort_if(PrintAgent::query()->where('active_store_id', $pair->store_id)->exists(), 409, 'Avval faol kompyuterni bekor qiling.');
            $token = Str::random(64);
            $agent = PrintAgent::query()->create(['store_id' => $pair->store_id, 'active_store_id' => $pair->store_id,
                'name'                                       => $name, 'version' => $version, 'token_hash' => hash('sha256', $token), 'last_seen_at' => now()]);
            $pair->update(['used_at' => now()]);

            return ['agent_id' => $agent->id, 'store_id' => (string) $pair->store_id, 'token' => $token, 'protocol_version' => 1];
        });
    }

    public function revoke(User $user, Store $store, string $id): void
    {
        PrintAccess::authorize($user, $store->id, 'manage_printing');
        $store->getConnection()->transaction(function () use ($store, $id): void {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $agent = PrintAgent::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($id);
            $agent->update(['revoked_at' => now(), 'active_store_id' => null]);
            PrintJob::query()->where('agent_id', $id)->whereIn('status', ['claimed', 'rendering', 'submitting'])
                ->update(['status' => 'unknown', 'error_code' => 'agent_revoked', 'message' => 'Eski kompyuterda chiqish holatini tekshiring.']);
            PrintJob::query()->where('agent_id', $id)->where('status', 'queued')->update(['status' => 'cancelled', 'error_code' => 'agent_revoked']);
        });
    }

    public function heartbeat(PrintAgent $agent, array $data): array
    {
        return $agent->getConnection()->transaction(function () use ($agent, $data): array {
            $agent = PrintAgent::query()->whereNull('revoked_at')->lockForUpdate()->findOrFail($agent->id);
            $agent->update(['last_seen_at' => now(), 'version' => $data['version']]);
            if (array_key_exists('printers', $data)) {
                $agent->printers()->update(['available' => false]);
                foreach ($data['printers'] as $printer) {
                    $agent->printers()->updateOrCreate(['local_key' => $printer['local_key']], [
                        'name'         => $printer['name'], 'driver' => $printer['driver'] ?? null, 'available' => true,
                        'capabilities' => $printer['capabilities'] ?? []]);
                }
            }

            return ['server_time' => now()->toIso8601String(), 'profiles' => PrintProfile::query()
                ->where('store_id', $agent->store_id)->where('active', true)->with('printer')
                ->get()->map(fn (PrintProfile $p): array => ['id' => $p->id, 'version' => $p->version,
                    'printer_id'                                  => $p->printer_id, 'printer_name' => $p->printer?->name,
                    'local_key'                                   => $p->printer?->local_key, 'loaded_media' => $p->printer?->loaded_media])->all()];
        });
    }
}
