<?php

namespace App\Services;

use App\Models\Debtor;
use Carbon\CarbonInterface;
use App\Models\FlutterDevice;
use Illuminate\Support\Carbon;
use App\Models\DebtorTransaction;
use Illuminate\Support\Facades\Gate;
use App\Jobs\SendDailyDebtorSummaryPush;

class DailyDebtorSummaryService
{
    public function __construct(private SmsBalanceService $smsBalances) {}

    /** @return array<int, array{name: string, data: array<string, array<string, int>>}> */
    public function totalsForDate(CarbonInterface $date): array
    {
        $rows = DebtorTransaction::query()->from('debtor_transactions as dt')
            ->join('debtors as d', 'dt.debtor_id', '=', 'd.id')
            ->join('stores as s', 'd.store_id', '=', 's.id')
            ->select(['s.id as store_id', 's.name as store_name', 'd.currency', 'dt.type'])
            ->selectRaw('SUM(dt.amount) as total')
            ->whereDate('dt.date', $date->toDateString())
            ->groupBy('s.id', 's.name', 'd.currency', 'dt.type')->orderBy('s.name')->get();
        $totals = [];
        foreach ($rows as $row) {
            $storeId  = (int) $row->store_id;
            $currency = strtoupper($row->currency ?? 'UZS');
            $totals[$storeId]['name'] ??= (string) ($row->store_name ?? "Do'kon");
            $totals[$storeId]['data'][$currency][$row->type] = (int) $row->total;
        }

        return $totals;
    }

    /** @param array<int, array{name: string, data: array<string, array<string, int>>}> $totals */
    public function message(string $reportDate, array $totals): string
    {
        $humanDate = Carbon::parse($reportDate, 'Asia/Tashkent')->format('d.m.Y');
        if ($totals === []) {
            return "Kunlik hisobot {$humanDate}\nBugun yangi qarz yoki to'lov qayd etilmadi.";
        }
        $lines = ["KUNLIK QARZDORLIK: {$humanDate}"];
        foreach ($totals as $store) {
            $lines[] = '';
            $lines[] = "Do'kon: {$store['name']}";
            foreach ($store['data'] as $currency => $amounts) {
                $lines[] = "{$currency}:";
                $lines[] = '  - Qarzdorlik: ' . number_format($amounts['debt'] ?? 0, 0, '.', ' ') . ' ' . $currency;
                $lines[] = "  - To'lov: " . number_format($amounts['payment'] ?? 0, 0, '.', ' ') . ' ' . $currency;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<int, array{name: string, data: array<string, array<string, int>>}>  $totals
     * @param  array{balance: ?float, stale: bool}|null  $smsBalance
     */
    public function pushMessage(array $totals, ?array $smsBalance = null): string
    {
        $sections = [];
        foreach ($totals as $store) {
            $lines = ['🏪 Do‘kon: ' . $store['name']];
            foreach ($store['data'] as $currency => $amounts) {
                $suffix  = strtoupper($currency) === 'UZS' ? 'so‘m' : strtoupper($currency);
                $lines[] = '📊 Bugungi qarzdorlik: ' . number_format($amounts['debt'] ?? 0, 0, '.', ' ') . ' ' . $suffix;
                $lines[] = '💳 Bugungi to‘lovlar: ' . number_format($amounts['payment'] ?? 0, 0, '.', ' ') . ' ' . $suffix;
            }
            $sections[] = implode("\n", $lines);
        }
        $message = $sections === [] ? '📊 Bugun yangi qarz yoki to‘lov qayd etilmadi.' : implode("\n\n", $sections);
        if ($smsBalance !== null) {
            $value  = $smsBalance['balance'];
            $amount = $value === null ? 'ma’lumot olinmadi' : number_format($value, 0, '.', ' ') . ' so‘m';
            if ($value !== null && $smsBalance['stale']) {
                $amount .= ' (oxirgi ma’lumot)';
            }
            $message .= "\n💰 SMS balansi: " . $amount;
        }

        return $message;
    }

    /**
     * @param  array<int, array{name: string, data: array<string, array<string, int>>}>  $totals
     */
    public function queuePushNotifications(string $reportDate, array $totals, FirebasePushSender $sender): int
    {
        if (!$sender->isConfigured()) {
            return 0;
        }
        $count         = 0;
        $smsBalance    = null;
        $balanceLoaded = false;
        FlutterDevice::query()->with(['user.roles', 'user.permissions', 'user.stores'])
            ->whereNull('revoked_at')->whereNotNull('push_token_hash')
            ->whereHas('token', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->chunkById(100, function ($devices) use ($reportDate, $totals, &$count, &$smsBalance, &$balanceLoaded): void {
                foreach ($devices as $device) {
                    $user = $device->user;
                    if (!$user || (!$user->isSuperAdmin() && !Gate::forUser($user)->allows('viewAny', Debtor::class)) || $user->stores->isEmpty()) {
                        continue;
                    }
                    $scoped        = array_intersect_key($totals, array_flip($user->stores->modelKeys()));
                    $deviceBalance = null;
                    if ($user->canViewSmsBalance()) {
                        if (!$balanceLoaded) {
                            $balance       = $this->smsBalances->getBalance();
                            $smsBalance    = ['balance' => $balance['balance'], 'stale' => $balance['stale']];
                            $balanceLoaded = true;
                        }
                        $deviceBalance = $smsBalance;
                    }
                    SendDailyDebtorSummaryPush::dispatch($device->id, $device->push_token_hash, $reportDate, $scoped, $deviceBalance);
                    $count++;
                }
            });

        return $count;
    }

    /** @return list<string> */
    public function notificationParts(string $message): array
    {
        $parts = [];
        while ($message !== '') {
            $part = mb_strcut($message, 0, 2000, 'UTF-8');
            if (strlen($message) > strlen($part)) {
                $newline = strrpos($part, "\n");
                if ($newline !== false && $newline > 0) {
                    $part = substr($part, 0, $newline + 1);
                }
            }
            $parts[] = $part;
            $message = substr($message, strlen($part));
        }

        return $parts;
    }
}
