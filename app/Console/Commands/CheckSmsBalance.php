<?php

namespace App\Console\Commands;

use App\Models\FlutterDevice;
use Illuminate\Console\Command;
use App\Jobs\SendSmsBalanceWarning;
use App\Services\SmsBalanceService;
use App\Services\FirebasePushSender;

class CheckSmsBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:check-balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Eskiz balansini tekshirish va kam qolganda ruxsatli qurilmalarga push yuborish';

    /**
     * Execute the console command.
     */
    public function handle(SmsBalanceService $balances, FirebasePushSender $sender): int
    {
        $balance = $balances->getBalance(refresh: true);
        if ($balance['stale'] || $balance['balance'] === null) {
            $this->warn('SMS balansini yangilab bo‘lmadi.');

            return self::FAILURE;
        }
        if ($balance['balance'] >= $balance['warning_threshold']) {
            return self::SUCCESS;
        }
        if (!config('sms.balance.push_enabled') || !$sender->isConfigured()) {
            return self::SUCCESS;
        }
        $notificationDate = now('Asia/Tashkent')->toDateString();
        FlutterDevice::query()->with('user.roles', 'user.permissions')->whereNull('revoked_at')->whereNotNull('push_token_hash')
            ->whereHas('token', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->chunkById(100, function ($devices) use ($notificationDate, $balances): void {
                foreach ($devices as $device) {
                    if ($device->user?->canViewSmsBalance()) {
                        SendSmsBalanceWarning::dispatch($device->id, $device->push_token_hash, $balances->cacheKey(), $notificationDate);
                    }
                }
            });

        return self::SUCCESS;
    }
}
