<?php

namespace App\Jobs;

use Throwable;
use App\Models\McpDelivery;
use App\Models\FlutterDevice;
use App\Observers\DebtorObserver;
use App\Services\FirebasePushSender;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;

class SendMcpNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId) {}

    public function handle(FirebasePushSender $firebase, DebtorObserver $observer): void
    {
        $claimed = McpDelivery::query()->whereKey($this->deliveryId)->where('status', 'queued')->update(['status' => 'sending']);
        if (!$claimed) {
            return;
        }
        $delivery = McpDelivery::query()->with('operation')->findOrFail($this->deliveryId);
        if ($delivery->operation->status !== 'applied') {
            $this->finish('skipped');

            return;
        }
        $payload = $delivery->payload;

        try {
            $messageId = null;
            if ($delivery->channel === 'firebase') {
                $device = FlutterDevice::query()->whereKey($delivery->device_id)->where('user_id', $payload['user_id'])
                    ->where('push_token_hash', $payload['push_token_hash'])->whereNull('revoked_at')->whereNotNull('personal_access_token_id')
                    ->whereHas('token', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                    ->whereHas('user.stores', fn ($query) => $query->whereKey($delivery->operation->store_id))->first();
                if (!$device) {
                    $this->finish('skipped');

                    return;
                }
                $messageId = $firebase->sendToToken($device->push_token, $payload['title'], $payload['body'], [
                    'type'     => 'mcp_debt_changed', 'operation_id' => $delivery->operation_id,
                    'store_id' => (string) $delivery->operation->store_id,
                ]);
                $this->finish($messageId ? 'accepted' : 'unknown', $messageId);

                return;
            }
            $observer->sendMcpChannel($payload, $delivery->channel);
            $this->finish('sent');
        } catch (ConnectionException) {
            $this->finish('unknown');
        } catch (Throwable $exception) {
            $this->finish('failed', null, $exception::class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('unknown', null, $exception ? $exception::class : null);
    }

    private function finish(string $status, ?string $messageId = null, ?string $errorType = null): void
    {
        McpDelivery::query()->whereKey($this->deliveryId)->whereIn('status', ['sending', 'queued'])->update([
            'status' => $status, 'message_id' => $messageId, 'error_type' => $errorType, 'finished_at' => now(),
        ]);
    }
}
