<?php

namespace App\Observers;

use App\Models\Debtor;
use App\Services\SmsService;
use Illuminate\Support\Carbon;
use App\Services\TelegramNotifier;

class DebtorObserver
{
    public function __construct(
        protected SmsService $sms,
        protected TelegramNotifier $telegram,
    ) {}

    /**
     * Yangi qarzdor yozuv yaratilganda
     */
    public function created(Debtor $debtor): void
    {
        if ($debtor->getConnection()->transactionLevel() > 0 && request()->is('api/flutter/*')) {
            $snapshot = clone $debtor;
            $debtor->getConnection()->afterCommit(fn () => $this->notifyCreated($snapshot));

            return;
        }
        $this->notifyCreated($debtor);
    }

    protected function notifyCreated(Debtor $debtor): void
    {
        $message = "Siz uchun {$debtor->store->address}da joylashgan {$debtor->store->name} do'konidan {$debtor->amount} UZS qarzdorlik qayd etildi. To'lov uchun +998913291187.";
        $this->sms->sendSms($debtor->client->phone, $message);

        $header = '🏬 <b>Do\'kon: ' . ($debtor->store->name ?? '-') . '</b>';

        $telegramMessage = implode(PHP_EOL, [
            $header,
            '🆕 <b>Yangi qarzdorlik</b>',
            '👤 ' . ($debtor->client->full_name ?? 'Ism ko\'rsatilmagan'),
            '📞 ' . $this->formatPhone($debtor->client->phone),
            '💵 ' . $this->formatAmount($debtor->amount, 'UZS'),
            '📅 ' . $this->formatDate($debtor->date),
        ]);

        $this->telegram->sendMessage($telegramMessage);
    }

    /**
     * Qarzdor yangilanganda (qarz miqdori o'zgarganda)
     */
    public function updated(Debtor $debtor): void
    {
        if ($debtor->getConnection()->transactionLevel() > 0 && request()->is('api/flutter/*')) {
            $snapshot = clone $debtor;
            $debtor->getConnection()->afterCommit(fn () => $this->notifyUpdated($snapshot));

            return;
        }
        $this->notifyUpdated($debtor);
    }

    protected function notifyUpdated(Debtor $debtor): void
    {
        if ($debtor->isDirty('amount')) {
            $originalAmount = (int) $debtor->getOriginal('amount');
            $currentAmount  = (int) $debtor->amount;
            $diff           = $currentAmount - $originalAmount;

            if ($currentAmount === 0) {
                $message = "{$debtor->store->name} do'konidagi qarzdorligingiz to'liq yopildi. Hamkorligingiz uchun rahmat! Savollar bo'lsa +998913291187.";
            } else {
                $message = "{$debtor->store->name} do'konida qarzingiz yangilandi. Joriy qarzdorlik: {$debtor->amount} {$debtor->currency}. Savollar bo'lsa +998913291187.";
            }

            $this->sms->sendSms($debtor->client->phone, $message);
            $this->sendTelegramUpdate($debtor, $diff);
        }
    }

    protected function sendTelegramUpdate(Debtor $debtor, int $diff): void
    {
        if ($diff === 0) {
            return;
        }

        $header = '🏬 <b>Do\'kon: ' . ($debtor->store->name ?? '-') . '</b>';

        if ($diff > 0) {
            $telegramMessage = implode(PHP_EOL, [
                $header,
                '➕ <b>Qarz qo\'shildi</b>',
                '👤 ' . ($debtor->client->full_name ?? 'Ism ko\'rsatilmagan'),
                '📞 ' . $this->formatPhone($debtor->client->phone),
                'Qo\'shimcha summa: ' . $this->formatAmount($diff, $debtor->currency),
                'Jami qarz: ' . $this->formatAmount($debtor->amount, $debtor->currency),
            ]);
        } else {
            $telegramMessage = implode(PHP_EOL, [
                $header,
                '✅ <b>To\'lov qabul qilindi</b>',
                '👤 ' . ($debtor->client->full_name ?? 'Ism ko\'rsatilmagan'),
                '📞 ' . $this->formatPhone($debtor->client->phone),
                'To\'lov summasi: ' . $this->formatAmount(abs($diff), $debtor->currency),
                'Qolgan qarz: ' . $this->formatAmount($debtor->amount, $debtor->currency),
            ]);

            if ($debtor->amount === 0) {
                $telegramMessage .= PHP_EOL . '✅ Qarzdorlik to\'liq yopildi';
            }
        }

        $this->telegram->sendMessage($telegramMessage);
    }

    protected function formatAmount(?int $amount, ?string $currency): string
    {
        $normalized   = $amount ?? 0;
        $currencyCode = $currency ? strtoupper($currency) : '';

        return trim(number_format($normalized, 0, '.', ' ') . ' ' . $currencyCode);
    }

    protected function formatPhone(?string $phone): string
    {
        if (!$phone || $phone === '0') {
            return '—';
        }

        return $phone;
    }

    protected function formatDate($date): string
    {
        if (!$date) {
            return now()->format('d.m.Y');
        }

        return Carbon::parse($date)->format('d.m.Y');
    }
}
