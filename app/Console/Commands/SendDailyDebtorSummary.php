<?php

namespace App\Console\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\DB;

class SendDailyDebtorSummary extends Command
{
    protected $signature = 'debtors:send-daily-summary';

    protected $description = "Kunlik qarzdorlik va to'lovlar statistikasi bo'yicha Telegramga hisobot yuboradi";

    public function handle(TelegramNotifier $telegram): int
    {
        $targetDate = Carbon::now('Asia/Tashkent')->startOfDay();

        $rows = DB::table('debtor_transactions as dt')
            ->join('debtors as d', 'dt.debtor_id', '=', 'd.id')
            ->join('stores as s', 'd.store_id', '=', 's.id')
            ->select(
                's.id as store_id',
                's.name as store_name',
                'd.currency',
                'dt.type',
                DB::raw('SUM(dt.amount) as total'),
            )
            ->whereDate('dt.date', $targetDate->toDateString())
            ->groupBy('s.id', 's.name', 'd.currency', 'dt.type')
            ->orderBy('s.name')
            ->get();

        // Build totals per store -> currency -> type
        $totalsByStore = [];
        foreach ($rows as $row) {
            $storeId   = (int) $row->store_id;
            $storeName = (string) ($row->store_name ?? "Do'kon");
            $currency  = strtoupper($row->currency ?? 'UZS');

            $totalsByStore[$storeId]['name'] ??= $storeName;
            $totalsByStore[$storeId]['data'][$currency][$row->type] = (int) $row->total;
        }

        $humanDate = $targetDate->format('d.m.Y');

        if (empty($totalsByStore)) {
            $telegram->sendMessage("Kunlik hisobot {$humanDate}\nBugun yangi qarz yoki to'lov qayd etilmadi.");
            $this->info("Hech qanday transaction topilmadi, bo'sh xabar yuborildi.");

            return self::SUCCESS;
        }

        // Single consolidated message with sections per store
        $lines = ["KUNLIK QARZDORLIK: {$humanDate}"];
        foreach ($totalsByStore as $store) {
            $lines[] = '';
            $lines[] = "Do'kon: {$store['name']}";

            $totals = $store['data'] ?? [];
            foreach ($totals as $currency => $data) {
                $debt    = (int) ($data['debt'] ?? 0);
                $payment = (int) ($data['payment'] ?? 0);

                $lines[] = "{$currency}:";
                $lines[] = '  - Qarzdorlik: ' . $this->formatAmount($debt, $currency);
                $lines[] = '  - To\'lov: ' . $this->formatAmount($payment, $currency);
            }
        }

        $telegram->sendMessage(implode(PHP_EOL, $lines));
        $this->info("Kunlik hisobot bitta xabarda, do'konlar bo'yicha jo'natildi.");

        return self::SUCCESS;
    }

    protected function formatAmount(int $amount, string $currency): string
    {
        return trim(number_format($amount, 0, '.', ' ') . ' ' . strtoupper($currency));
    }
}
