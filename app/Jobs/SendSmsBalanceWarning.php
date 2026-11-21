<?php

namespace App\Jobs;

use RuntimeException;
use App\Models\FlutterDevice;
use App\Services\SmsBalanceService;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSmsBalanceWarning implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $backoff = 60;

    public function __construct(public int $deviceId, public string $tokenHash, public string $accountKey, public string $notificationDate) {}

    /**
     * Execute the job.
     */
    public function handle(SmsBalanceService $balances, FirebasePushSender $sender): void
    {
        $today = now('Asia/Tashkent');
        if ($today->toDateString() !== $this->notificationDate || $today->hour < 20) {
            return;
        }
        if (!config('sms.balance.push_enabled') || !$sender->isConfigured() || $balances->cacheKey() !== $this->accountKey) {
            return;
        }
        $balance = $balances->getBalance();
        if ($balance['stale'] || $balance['balance'] === null || $balance['balance'] >= $balance['warning_threshold']) {
            return;
        }
        $device = FlutterDevice::query()->with('user')->whereKey($this->deviceId)->whereNull('revoked_at')->where('push_token_hash', $this->tokenHash)
            ->whereHas('token', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))->first();
        if (!$device?->push_token || !$device->user?->canViewSmsBalance()) {
            return;
        }
        $key  = $this->accountKey . ':push:' . $this->notificationDate . ':' . $device->id;
        $lock = Cache::lock($key . ':lock', 60);
        if (!$lock->get()) {
            return;
        }

        try {
            if (Cache::has($key)) {
                return;
            }
            $title     = $balance['status'] === 'empty' ? 'SMS balansi tugagan' : 'SMS balansi kam qoldi';
            $messageId = $sender->sendToToken(
                $device->push_token,
                $title,
                'Umumiy SMS balansi: ' . number_format($balance['balance'], 0, '.', ' ') . ' so‘m. Eskiz hisobini to‘ldiring.',
                ['type' => 'sms_balance_low', 'status' => $balance['status']],
            );
            if (!$messageId) {
                throw new RuntimeException('SMS balance push was not accepted.');
            }
            Cache::put($key, true, now()->addDays(2));
        } finally {
            $lock->release();
        }
    }
}
