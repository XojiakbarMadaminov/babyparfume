<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SmsService
{
    protected $email;
    protected $password;
    protected $from;

    public function __construct()
    {
        $this->email    = config('sms.eskiz.email');
        $this->password = config('sms.eskiz.password');
        $this->from     = '4546';
    }

    /**
     * Get or refresh Eskiz token
     */
    public function tokenCacheKey(): string
    {
        return 'eskiz_token:' . hash('sha256', $this->email . '|' . $this->password);
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    public function getToken(): ?string
    {
        // Tokenni cache da 29 kun saqlaymiz
        $cacheKey = $this->tokenCacheKey();

        // Cacheda bo‘lsa va muddat tugamagan bo‘lsa — ishlatamiz
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Login orqali token olamiz
        $response = Http::asForm()->connectTimeout(3)->timeout(5)->post('https://notify.eskiz.uz/api/auth/login', [
            'email'    => $this->email,
            'password' => $this->password,
        ]);

        if ($response->ok() && isset($response['data']['token'])) {
            $token = $response['data']['token'];
            // Cachega 29 kun saqlaymiz (Eskiz token 30 kun ishlaydi)
            Cache::put($cacheKey, $token, now()->addDays(29));

            return $token;
        }

        // Error handling/log
        Log::error('Eskiz token olishda xatolik', [
            'response' => $response->json(),
        ]);

        return null;
    }

    /**
     * Send SMS via Eskiz API
     */
    public function sendSms($phone, $message, $callbackUrl = null)
    {
        $token = $this->getToken();
        if (!$token) {
            return [
                'success' => false,
                'error'   => 'Token not found',
                'data'    => null,
            ];
        }

        $data = [
            'mobile_phone' => $this->sanitizePhone($phone),
            'message'      => $message,
            'from'         => $this->from,
        ];
        if ($callbackUrl) {
            $data['callback_url'] = $callbackUrl;
        }

        $response = Http::withToken($token)
            ->asForm()
            ->post('https://notify.eskiz.uz/api/message/sms/send', $data);

        if ($response->ok() && isset($response['status']) && $response['status'] === 'waiting') {
            return [
                'success' => true,
                'data'    => $response->json(),
                'error'   => null,
            ];
        } else {
            Log::error('Eskiz SMS xatolik', [
                'phone'    => $phone,
                'message'  => $message,
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'data'    => $response->json(),
                'error'   => $response->json()['message'] ?? 'Unknown error',
            ];
        }
    }

    /**
     * Telefon raqamni 998XXXXXXXXX formatga keltirish
     */
    protected function sanitizePhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            $phone = '998' . $phone;
        }

        return $phone;
    }
}
