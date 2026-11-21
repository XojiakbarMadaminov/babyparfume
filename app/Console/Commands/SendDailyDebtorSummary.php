<?php

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Log;
use App\Services\FirebasePushSender;
use App\Services\DailyDebtorSummaryService;

class SendDailyDebtorSummary extends Command
{
    protected $signature = 'debtors:send-daily-summary';

    protected $description = 'Kunlik qarzdorlik va to‘lovlar hisobotini Telegram va APK push orqali yuboradi';

    public function handle(TelegramNotifier $telegram, DailyDebtorSummaryService $summaries, FirebasePushSender $sender): int
    {
        $date   = now('Asia/Tashkent')->startOfDay();
        $totals = $summaries->totalsForDate($date);
        $failed = false;

        try {
            if (!$telegram->sendMessage($summaries->message($date->toDateString(), $totals))) {
                $failed = true;
                $this->warn('Kunlik qarzdorlik hisoboti Telegramga yuborilmadi.');
            }
        } catch (Throwable $exception) {
            $failed = true;
            Log::warning('Kunlik qarzdorlik hisobotini Telegramga yuborib bo‘lmadi.', ['error_type' => $exception::class]);
        }

        try {
            $count = $summaries->queuePushNotifications($date->toDateString(), $totals, $sender);
            $this->info("Kunlik qarzdorlik hisoboti: {$count} ta qurilmaga push navbatga qo‘yildi.");
        } catch (Throwable $exception) {
            $failed = true;
            Log::warning('Kunlik qarzdorlik pushlarini navbatga qo‘yib bo‘lmadi.', ['error_type' => $exception::class]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
