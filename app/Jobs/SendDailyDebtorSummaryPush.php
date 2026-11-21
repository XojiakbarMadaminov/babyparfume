<?php

namespace App\Jobs;

use RuntimeException;
use App\Models\Debtor;
use App\Models\FlutterDevice;
use Illuminate\Support\Carbon;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\DailyDebtorSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class SendDailyDebtorSummaryPush implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public int $backoff = 60;

    /** @var array{balance: ?float, stale: bool}|null */
    public ?array $smsBalance = null;

    /**
     * @param  array<int, array{name: string, data: array<string, array<string, int>>}>  $totals
     * @param  array{balance: ?float, stale: bool}|null  $smsBalance
     */
    public function __construct(public int $deviceId, public string $tokenHash, public string $reportDate, public array $totals, ?array $smsBalance = null)
    {
        $this->smsBalance = $smsBalance;
    }

    /**
     * Execute the job.
     */
    public function handle(FirebasePushSender $sender, DailyDebtorSummaryService $summaries): void
    {
        $now = now('Asia/Tashkent');
        if ($now->toDateString() !== $this->reportDate || $now->hour < 22 || !$sender->isConfigured()) {
            return;
        }
        $device = FlutterDevice::query()->with('user')->whereKey($this->deviceId)->where('push_token_hash', $this->tokenHash)->whereNull('revoked_at')
            ->whereHas('token', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))->first();
        $user = $device?->user;
        if (!$device?->push_token || !$user || (!$user->isSuperAdmin() && !Gate::forUser($user)->allows('viewAny', Debtor::class))) {
            return;
        }
        $storeIds = $user->stores()->pluck('stores.id')->all();
        if ($storeIds === []) {
            return;
        }
        $totals     = array_intersect_key($this->totals, array_flip($storeIds));
        $smsBalance = $user->canViewSmsBalance() ? $this->smsBalance : null;
        $parts      = $summaries->notificationParts($summaries->pushMessage($totals, $smsBalance));
        $key        = 'daily_debtor_summary:' . $this->reportDate . ':' . $device->id;
        $lock       = Cache::lock($key . ':lock', 90);
        if (!$lock->get()) {
            return;
        }

        try {
            if (Cache::has($key)) {
                return;
            }
            foreach ($parts as $index => $body) {
                $partKey = $key . ':part:' . $index . ':' . hash('sha256', $body);
                if (Cache::has($partKey)) {
                    continue;
                }
                $title = 'Kunlik qarzdorlik: ' . Carbon::parse($this->reportDate)->format('d.m.Y');
                if (count($parts) > 1) {
                    $title .= ' (' . ($index + 1) . '/' . count($parts) . ')';
                }
                $messageId = $sender->sendToToken($device->push_token, $title, $body, [
                    'type' => 'daily_debtor_summary', 'report_date' => $this->reportDate, 'part' => (string) ($index + 1),
                ]);
                if (!$messageId) {
                    throw new RuntimeException('Daily debtor summary push was not accepted.');
                }
                Cache::put($partKey, true, now()->addDays(2));
            }
            Cache::put($key, true, now()->addDays(2));
        } finally {
            $lock->release();
        }
    }
}
