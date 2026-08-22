<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\Debtor;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\TelegramNotifier;

class SendOverdueDebtorRanking extends Command
{
    protected $signature = 'debtors:send-overdue-ranking {--limit=10 : Har bir do\'kon uchun nechta qarzdor chiqarilishi} {--store= : Muayyan do\'kon ID bo\'yicha filter}';

    protected $description = "Eng ko'p vaqtdan beri qarzini to'lamagan TOP qarzdorlar ro'yxatini Telegramga yuboradi";

    public function handle(TelegramNotifier $telegram): int
    {
        $limit   = (int) ($this->option('limit') ?: 10);
        $storeId = $this->option('store');

        $storesQuery = Store::query()->orderBy('name');
        if ($storeId) {
            $storesQuery->where('id', $storeId);
        }
        $stores = $storesQuery->get();

        if ($stores->isEmpty()) {
            $this->warn("Do'konlar topilmadi.");

            return self::SUCCESS;
        }

        $now            = Carbon::now('Asia/Tashkent')->startOfDay();
        $reportsByStore = [];

        foreach ($stores as $store) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, Debtor> $debtors */
            $debtors = Debtor::withoutGlobalScopes()
                ->where('store_id', $store->id)
                ->where('amount', '>', 0)
                ->with(['client', 'transactions'])
                ->get();

            $storeDebtorList = [];

            foreach ($debtors as $debtor) {
                // Latest payment transaction date
                $lastPaymentDate = $debtor->transactions
                    ->where('type', 'payment')
                    ->max('date');

                // Initial debt date fallback
                $initialDebtDate = $debtor->date
                    ?: $debtor->transactions->where('type', 'debt')->min('date')
                    ?: $debtor->created_at;

                $lastActivityDate = $lastPaymentDate
                    ? Carbon::parse($lastPaymentDate)->startOfDay()
                    : ($initialDebtDate ? Carbon::parse($initialDebtDate)->startOfDay() : $now);

                $daysOverdue = max(0, (int) $lastActivityDate->diffInDays($now));

                $fullName = $debtor->client?->full_name ?: ($debtor->note ? "Mijoz ({$debtor->note})" : "Noma'lum mijoz");
                $phone    = $debtor->client?->phone ?: '-';

                $storeDebtorList[] = [
                    'debtor_id'          => $debtor->id,
                    'full_name'          => $fullName,
                    'phone'              => $phone,
                    'amount'             => (int) $debtor->amount,
                    'currency'           => strtoupper($debtor->currency ?? 'UZS'),
                    'last_activity_date' => $lastActivityDate->format('d.m.Y'),
                    'has_payment'        => !is_null($lastPaymentDate),
                    'days_overdue'       => $daysOverdue,
                ];
            }

            // Sort by days_overdue DESC, then by amount DESC
            usort($storeDebtorList, function ($a, $b) {
                if ($b['days_overdue'] === $a['days_overdue']) {
                    return $b['amount'] <=> $a['amount'];
                }

                return $b['days_overdue'] <=> $a['days_overdue'];
            });

            $topDebtors = array_slice($storeDebtorList, 0, $limit);

            if (!empty($topDebtors)) {
                $reportsByStore[$store->id] = [
                    'store_name' => $store->name,
                    'debtors'    => $topDebtors,
                ];
            }
        }

        $humanDate = $now->format('d.m.Y');

        if (empty($reportsByStore)) {
            $msg = "⏳ <b>ENG KO'P VAQTDAN BERI TO'LANMAGAN QARZDORLAR ({$humanDate})</b>\n\nQarzdorlar topilmadi.";
            $telegram->sendMessage($msg);
            $this->info('Qarzdorlar topilmadi, xabar yuborildi.');

            return self::SUCCESS;
        }

        $lines = ["⏳ <b>ENG KO'P VAQTDAN BERI TO'LANMAGAN QARZDORLAR (TOP-{$limit})</b>", "Sana: {$humanDate}"];

        foreach ($reportsByStore as $storeData) {
            $lines[] = '';
            $lines[] = "🏬 <b>Do'kon: " . htmlspecialchars($storeData['store_name'], ENT_NOQUOTES, 'UTF-8') . '</b>';

            foreach ($storeData['debtors'] as $idx => $d) {
                $num             = $idx + 1;
                $formattedAmount = $this->formatAmount($d['amount'], $d['currency']);
                $name            = htmlspecialchars($d['full_name'], ENT_NOQUOTES, 'UTF-8');
                $phone           = htmlspecialchars($d['phone'], ENT_NOQUOTES, 'UTF-8');
                $dateLabel       = $d['has_payment'] ? "oxirgi to'lov: {$d['last_activity_date']}" : "qarz sanasi: {$d['last_activity_date']}";

                $lines[] = "{$num}. <b>{$name}</b> ({$phone})";
                $lines[] = "   💰 Qarz: <b>{$formattedAmount}</b>";
                $lines[] = "   ⏱ To'lanmagan: <b>{$d['days_overdue']} kun</b> ({$dateLabel})";
            }
        }

        $telegramMessage = implode("\n", $lines);
        $telegram->sendMessage($telegramMessage);

        $this->info("Top qarzdorlar statistikasi Telegramga jo'natildi.");

        // Also display table in console output
        foreach ($reportsByStore as $storeData) {
            $this->newLine();
            $this->info("Do'kon: " . $storeData['store_name']);
            $this->table(
                ['#', 'F.I.Sh', 'Telefon', 'Qoldiq Qarz', 'Oxirgi Harakat', 'Muddati (Kun)'],
                array_map(fn ($idx, $d) => [
                    $idx + 1,
                    $d['full_name'],
                    $d['phone'],
                    $this->formatAmount($d['amount'], $d['currency']),
                    $d['last_activity_date'] . ($d['has_payment'] ? ' (to\'lov)' : ' (qarz)'),
                    $d['days_overdue'] . ' kun',
                ], array_keys($storeData['debtors']), $storeData['debtors'])
            );
        }

        return self::SUCCESS;
    }

    protected function formatAmount(int $amount, string $currency): string
    {
        return trim(number_format($amount, 0, '.', ' ') . ' ' . strtoupper($currency));
    }
}
