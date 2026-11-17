<?php

namespace App\Services;

use App\Models\User;
use App\Models\Debtor;
use App\Models\McpOperation;
use App\Models\FlutterDevice;
use App\Jobs\SendMcpNotification;

class McpNotificationService
{
    public function record(McpOperation $operation, User $user, Debtor $debtor, int $before): void
    {
        $debtor->loadMissing(['client', 'store']);
        $payload = [
            'event'  => $operation->type === 'create' ? 'created' : 'updated',
            'before' => $before,
            'debtor' => $debtor->only(['id', 'store_id', 'client_id', 'amount', 'currency', 'date']),
            'client' => $debtor->client?->only(['id', 'full_name', 'phone']),
            'store'  => $debtor->store->only(['id', 'name', 'address']),
        ];
        if ($operation->type === 'create' || $before !== (int) $debtor->amount) {
            foreach (['sms', 'telegram'] as $channel) {
                $this->queue($operation, $channel, 0, $payload);
            }
        }
        $devices = FlutterDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->whereNotNull('personal_access_token_id')->whereNotNull('push_token_hash')->get();
        foreach ($devices as $device) {
            $this->queue($operation, 'firebase', $device->id, [
                'user_id' => $user->id, 'push_token_hash' => $device->push_token_hash,
                'title'   => 'MCP orqali qarz o‘zgartirildi',
                'body'    => $debtor->store->name . ': ' . number_format($before, 0, '.', ' ') . ' → ' . number_format((int) $debtor->amount, 0, '.', ' ') . ' ' . $debtor->currency,
            ]);
        }
        if ($devices->isEmpty()) {
            $operation->deliveries()->create([
                'channel' => 'firebase', 'device_id' => 0, 'payload' => [],
                'status'  => 'no_device', 'finished_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function queue(McpOperation $operation, string $channel, int $deviceId, array $payload): void
    {
        $delivery = $operation->deliveries()->create(['channel' => $channel, 'device_id' => $deviceId, 'payload' => $payload]);
        SendMcpNotification::dispatch($delivery->id)->afterCommit();
    }
}
