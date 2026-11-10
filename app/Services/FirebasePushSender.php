<?php

namespace App\Services;

use RuntimeException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Auth\Credentials\ServiceAccountCredentials;

class FirebasePushSender
{
    public const TOPIC = 'oson_pos_announcements';

    public function isConfigured(): bool
    {
        $project = config('services.firebase_push.project_id');
        $path    = config('services.firebase_push.credentials');

        return is_string($project)
            && preg_match('/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/', $project) === 1
            && is_string($path) && is_file($path) && is_readable($path);
    }

    public function send(string $title, string $body): ?string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Firebase push credentials are not configured.');
        }

        $project  = config('services.firebase_push.project_id');
        $response = Http::asJson()
            ->acceptJson()
            ->withToken($this->accessToken())
            ->connectTimeout(5)
            ->timeout(12)
            ->withoutRedirecting()
            ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                'message' => [
                    'topic'        => self::TOPIC,
                    'notification' => ['title' => $title, 'body' => $body],
                    'android'      => [
                        'priority'     => 'high',
                        'notification' => ['channel_id' => self::TOPIC],
                    ],
                ],
            ]);

        if ($response->serverError() || $response->status() === 408) {
            return null;
        }

        $messageId = $response->throw()->json('name');

        return is_string($messageId) && $messageId !== '' ? $messageId : null;
    }

    protected function accessToken(): string
    {
        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/firebase.messaging',
            config('services.firebase_push.credentials'),
        );
        $handler = HttpHandlerFactory::build(new Client(['connect_timeout' => 4, 'timeout' => 8]));
        $token   = $credentials->fetchAuthToken($handler)['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Google OAuth did not return an access token.');
        }

        return $token;
    }
}
