<?php

use Illuminate\Support\Str;
use App\Models\FlutterDevice;
use App\Services\McpDebtService;
use App\Jobs\SendMcpNotification;
use App\Observers\DebtorObserver;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->ctx        = mcpTestContext();
    $this->connection = mcpTestConnection($this->ctx['user'], [$this->ctx['storeA']->id]);
    $this->debtor     = mcpTestDebtor($this->ctx['storeA']);
    $token            = $this->ctx['user']->createToken('flutter:test', ['flutter:access'], now()->addDay());
    $this->device     = FlutterDevice::query()->create([
        'user_id'                  => $this->ctx['user']->id, 'device_uuid' => (string) Str::uuid(), 'name' => 'Android', 'platform' => 'android',
        'personal_access_token_id' => $token->accessToken->id,
    ]);
    $this->device->forceFill(['push_token' => 'private-device-token-12345', 'push_token_hash' => hash('sha256', 'private-device-token-12345')])->save();
    $this->mobileToken = $token->plainTextToken;
});

it('queues all three channels for an approved mcp change and delivers each at most once', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 300000,
    ]);
    app(McpDebtService::class)->apply($this->ctx['user'], $operation);
    Queue::assertPushed(SendMcpNotification::class, 3);
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->once()->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->once()->andReturn(true);
    $sender = $this->mock(FirebasePushSender::class);
    $sender->shouldReceive('sendToToken')->once()->withArgs(function (string $token, string $title, string $body, array $data) use ($operation): bool {
        return $token === $this->device->push_token && str_contains($body, '800 000') && $data['operation_id'] === $operation->id;
    })->andReturn('projects/oson-pos/messages/private-1');
    $observer = app(DebtorObserver::class);
    foreach ($operation->deliveries()->get() as $delivery) {
        $job = new SendMcpNotification($delivery->id);
        $job->handle($sender, $observer);
        $job->handle($sender, $observer);
    }
    expect($operation->deliveries()->where('status', 'sent')->count())->toBe(2)
        ->and($operation->deliveries()->where('status', 'accepted')->count())->toBe(1);
});

it('continues telegram and firebase delivery when sms fails', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'payment', 'debtor_id' => $this->debtor->id, 'amount' => 100000,
    ]);
    app(McpDebtService::class)->apply($this->ctx['user'], $operation);
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->once()->andReturn(['success' => false]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->once()->andReturn(true);
    $sender = $this->mock(FirebasePushSender::class);
    $sender->shouldReceive('sendToToken')->once()->andReturn('private-message');
    foreach ($operation->deliveries()->get() as $delivery) {
        (new SendMcpNotification($delivery->id))->handle($sender, app(DebtorObserver::class));
    }
    expect($operation->deliveries()->where('channel', 'sms')->first()->status)->toBe('failed')
        ->and($operation->deliveries()->where('channel', 'telegram')->first()->status)->toBe('sent')
        ->and($operation->deliveries()->where('channel', 'firebase')->first()->status)->toBe('accepted')
        ->and($this->debtor->fresh()->amount)->toBe(400000);
});

it('never sends private debt changes to the public topic', function (): void {
    config()->set('services.firebase_push.project_id', 'oson-pos');
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'private-message'])]);
    $sender = new class extends FirebasePushSender
    {
        public function isConfigured(): bool
        {
            return true;
        }

        protected function accessToken(): string
        {
            return 'test-google-token';
        }
    };
    expect($sender->sendToToken('private-device-token', 'MCP', 'Qarz o‘zgardi', ['type' => 'mcp_debt_changed']))->toBe('private-message');
    Http::assertSent(fn ($request): bool => $request['message']['token'] === 'private-device-token' && !isset($request['message']['topic']));
});

it('skips firebase when the target device or store membership is revoked', function (): void {
    $operation = app(McpDebtService::class)->prepare($this->connection, $this->ctx['user'], [
        'request_id' => (string) Str::uuid(), 'type' => 'debt', 'debtor_id' => $this->debtor->id, 'amount' => 300000,
    ]);
    app(McpDebtService::class)->apply($this->ctx['user'], $operation);
    $this->device->forceFill(['revoked_at' => now()])->save();
    $sender = $this->mock(FirebasePushSender::class);
    $sender->shouldNotReceive('sendToToken');
    $delivery = $operation->deliveries()->where('channel', 'firebase')->firstOrFail();
    (new SendMcpNotification($delivery->id))->handle($sender, app(DebtorObserver::class));
    expect($delivery->fresh()->status)->toBe('skipped');
});

it('registers encrypted push tokens only for the authenticated mobile device and clears them on logout', function (): void {
    $this->withToken($this->mobileToken)->postJson('/api/flutter/v1/devices/push-token', [
        'token' => 'fresh-private-device-token-12345',
    ])->assertNoContent();
    expect($this->device->fresh()->push_token)->toBe('fresh-private-device-token-12345')
        ->and($this->device->fresh()->getRawOriginal('push_token'))->not->toBe('fresh-private-device-token-12345');
    $this->postJson('/api/flutter/v1/logout')->assertNoContent();
    expect($this->device->fresh()->push_token)->toBeNull();
});

it('keeps ordinary non mcp sms and telegram notifications working without private push', function (): void {
    $this->mock(App\Services\SmsService::class)->shouldReceive('sendSms')->once()->andReturn(['success' => true]);
    $this->mock(App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->once()->andReturn(true);
    $this->debtor->amount = 400000;
    $this->debtor->save();
    $this->assertDatabaseCount('mcp_deliveries', 0);
});
