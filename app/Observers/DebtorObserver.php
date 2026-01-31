<?php

namespace App\Observers;

use App\Models\Debtor;
use App\Services\SmsService;
use Illuminate\Support\Carbon;
use App\Services\TelegramDebtNotifier;

class DebtorObserver
{
    protected SmsService $sms;
    protected TelegramDebtNotifier $telegram;

    public function __construct(SmsService $sms, TelegramDebtNotifier $telegram)
    {
        $this->sms      = $sms;
        $this->telegram = $telegram;
    }

    /**
     * Yangi qarzdor yozuv yaratilganda
     */
    public function created(Debtor $debtor): void
    {
        $message = "Siz uchun Qumtepada joylashgan Million parfume do'konidan {$debtor->amount} {$debtor->currency} qarzdorlik qayd etildi. To'lov uchun +998913291187.";
        $this->sms->sendSms($debtor->phone, $message);

        $telegramMessage = implode(PHP_EOL, [
            '🆕 <b>Yangi qarzdorlik</b>',
            '👤 ' . ($debtor->full_name ?? 'Ism ko\'rsatilmagan'),
            '📞 ' . $this->formatPhone($debtor->phone),
            '💰 ' . $this->formatAmount($debtor->amount, $debtor->currency),
            '🗓 ' . $this->formatDate($debtor->date),
        ]);

        $this->telegram->sendMessage($telegramMessage);
    }

    /**
     * Qarzdor yangilanganda (qarz miqdori o‘zgarganda)
     */
    public function updated(Debtor $debtor): void
    {
        if ($debtor->isDirty('amount')) {
            $originalAmount = (int) $debtor->getOriginal('amount');
            $currentAmount  = (int) $debtor->amount;
            $diff           = $currentAmount - $originalAmount;

            if ($currentAmount == 0) {
                $message = "Million parfume do'konidagi qarzdorligingiz to'liq yopildi. Hamkorligingiz uchun rahmat! Savollar bo‘lsa +998913291187.";
            } else {
                // Qarz yangilandi (qisman to‘landi yoki qo‘shildi)
                $message = "Million parfume do'konida qarzingiz yangilandi. Joriy qarzdorlik: {$debtor->amount} {$debtor->currency}. Savollar bo‘lsa +998913291187.";
            }

            $this->sms->sendSms($debtor->phone, $message);
            $this->sendTelegramUpdate($debtor, $diff);
        }
    }

    protected function sendTelegramUpdate(Debtor $debtor, int $diff): void
    {
        if ($diff === 0) {
            return;
        }

        if ($diff > 0) {
            $telegramMessage = implode(PHP_EOL, [
                '➕ <b>Qarz qo‘shildi</b>',
                '👤 ' . ($debtor->full_name ?? 'Ism ko\'rsatilmagan'),
                '📞 ' . $this->formatPhone($debtor->phone),
                'Qo\'shimcha summa: ' . $this->formatAmount($diff, $debtor->currency),
                'Jami qarz: ' . $this->formatAmount($debtor->amount, $debtor->currency),
            ]);
        } else {
            $telegramMessage = implode(PHP_EOL, [
                '💸 <b>To‘lov qabul qilindi</b>',
                '👤 ' . ($debtor->full_name ?? 'Ism ko\'rsatilmagan'),
                '📞 ' . $this->formatPhone($debtor->phone),
                'To‘lov summasi: ' . $this->formatAmount(abs($diff), $debtor->currency),
                'Qolgan qarz: ' . $this->formatAmount($debtor->amount, $debtor->currency),
            ]);

            if ($debtor->amount == 0) {
                $telegramMessage .= PHP_EOL . '🎉 Qarzdorlik to‘liq yopildi';
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
