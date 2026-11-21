<?php

namespace App\Services;

use Throwable;
use RuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SmsBalanceService
{
    public function __construct(private SmsService $sms) {}

    public function cacheKey(): string
    {
        return 'sms_balance:' . hash('sha256', config('sms.eskiz.email') . '|' . config('sms.eskiz.password'));
    }

    /** @return array{balance: ?float, currency: string, provider: string, scope: string, updated_at: ?string, stale: bool, status: string, warning_threshold: float} */
    public function getBalance(bool $refresh = false): array
    {
        $key      = $this->cacheKey();
        $snapshot = Cache::get($key);
        $attempt  = Cache::get($key . ':attempt');
        $interval = $refresh || ($attempt['failed'] ?? false)
            ? (int) config('sms.balance.retry_seconds', 60)
            : (int) config('sms.balance.cache_seconds', 300);

        if (config('sms.eskiz.email') && config('sms.eskiz.password') && (!$attempt || now()->timestamp - $attempt['at'] >= $interval)) {
            $lock = Cache::lock($key . ':fetch', 30);
            if ($lock->get()) {
                try {
                    Cache::put($key . ':attempt', ['at' => now()->timestamp, 'failed' => false], now()->addDay());
                    $token = $this->sms->getToken();
                    if (!$token) {
                        throw new RuntimeException('SMS balance authentication failed.');
                    }
                    $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)
                        ->get('https://notify.eskiz.uz/api/user/get-limit');
                    if ($response->unauthorized()) {
                        $this->sms->forgetToken();
                        $token = $this->sms->getToken();
                        if (!$token) {
                            throw new RuntimeException('SMS balance authentication failed.');
                        }
                        $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)
                            ->get('https://notify.eskiz.uz/api/user/get-limit');
                    }
                    $balance = $response->json('data.balance');
                    if (!$response->successful() || $response->json('status') !== 'success' || !is_numeric($balance) || !is_finite((float) $balance)) {
                        throw new RuntimeException('Invalid SMS balance response.');
                    }
                    $snapshot = ['balance' => (float) $balance, 'updated_at' => now()->toISOString()];
                    Cache::put($key, $snapshot, now()->addDays(30));
                } catch (Throwable $exception) {
                    Cache::put($key . ':attempt', ['at' => now()->timestamp, 'failed' => true], now()->addDay());
                    Log::warning('SMS balansini olish imkoni bo‘lmadi.', ['error_type' => $exception::class]);
                } finally {
                    $lock->release();
                }
            }
        }

        $attempt   = Cache::get($key . ':attempt');
        $balance   = $snapshot['balance'] ?? null;
        $threshold = max(0, (float) config('sms.balance.warning_threshold', 20000));

        return [
            'balance'    => $balance,
            'currency'   => 'UZS',
            'provider'   => 'Eskiz',
            'scope'      => 'account',
            'updated_at' => $snapshot['updated_at'] ?? null,
            'stale'      => $snapshot === null || ($attempt['failed'] ?? false)
                || Carbon::parse($snapshot['updated_at'])->addSeconds((int) config('sms.balance.cache_seconds', 300))->isPast(),
            'status'            => $balance === null ? 'unavailable' : ($balance <= 0 ? 'empty' : ($balance < $threshold ? 'low' : 'normal')),
            'warning_threshold' => $threshold,
        ];
    }
}
