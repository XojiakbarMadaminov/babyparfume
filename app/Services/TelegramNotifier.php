<?php

namespace App\Services;

use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    protected ?string $botToken;
    protected ?string $chatId;

    public function __construct(?string $botToken = null, ?string $chatId = null)
    {
        $settings = TelegramSetting::query()->first();

        $this->botToken = $settings?->bot_token ?: config('services.telegram.bot_token');
        $this->chatId   = $settings?->debt_chat_id ?: config('services.telegram.debt_chat_id');
    }

    /**
     * Send plain text message to Telegram chat
     */
    public function sendMessage(string $message, array $extraPayload = []): bool
    {
        if (blank($this->botToken) || blank($this->chatId)) {
            Log::warning('Telegram sozlamalari to\'liq emas, xabar yuborilmadi.');

            return false;
        }

        $payload = array_merge([
            'chat_id' => $this->chatId,
            'text'    => $message,
        ], $extraPayload);

        $payload['parse_mode'] ??= 'HTML';
        $payload['disable_web_page_preview'] ??= true;

        $response = Http::timeout(10)
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

        if ($response->ok() && $response->json('ok')) {
            return true;
        }

        Log::error('Telegramga xabar yuborishda xatolik', [
            'payload'  => $payload,
            'response' => $response->json(),
        ]);

        return false;
    }
}
