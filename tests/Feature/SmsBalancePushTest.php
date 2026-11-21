<?php

use App\Models\User;
use App\Models\FlutterDevice;
use App\Jobs\SendSmsBalanceWarning;
use App\Services\SmsBalanceService;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-21 20:00:00', 'Asia/Tashkent'));
    Queue::fake();
    Cache::flush();
    config(['sms.eskiz.email' => 'sms@example.test', 'sms.eskiz.password' => 'password', 'sms.balance.push_enabled' => true, 'sms.balance.warning_threshold' => 20000]);
    $this->balances   = app(SmsBalanceService::class);
    $this->key        = $this->balances->cacheKey();
    $this->setBalance = function (float $balance): void {
        Cache::put($this->key, ['balance' => $balance, 'updated_at' => now()->toISOString()], now()->addDays(30));
        Cache::put($this->key . ':attempt', ['at' => now()->timestamp, 'failed' => false], now()->addDay());
    };
    ($this->setBalance)(5000);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(Permission::findOrCreate('view_sms_balance', 'web'));
    $token        = $this->user->createToken('flutter:test', ['flutter:access'], now()->addDays(2));
    $this->device = FlutterDevice::factory()->create(['user_id' => $this->user->id, 'personal_access_token_id' => $token->accessToken->id]);
    $this->device->forceFill(['push_token' => 'private-token', 'push_token_hash' => hash('sha256', 'private-token')])->save();
    $this->sender = $this->mock(FirebasePushSender::class);
    $this->sender->shouldReceive('isConfigured')->andReturnTrue();
});

it('queues low balance pushes only for permitted active devices', function (): void {
    $other = User::factory()->create();
    $token = $other->createToken('flutter:other', ['flutter:access'], now()->addDay());
    FlutterDevice::factory()->create(['user_id' => $other->id, 'personal_access_token_id' => $token->accessToken->id])
        ->forceFill(['push_token' => 'other-token', 'push_token_hash' => hash('sha256', 'other-token')])->save();
    $this->artisan('sms:check-balance')->assertSuccessful();
    Queue::assertPushed(SendSmsBalanceWarning::class, 1);
    Queue::assertPushed(SendSmsBalanceWarning::class, fn ($job): bool => $job->deviceId === $this->device->id && $job->notificationDate === '2026-11-21');
});

it('does not send when balance is healthy, stale, or pushes are disabled', function (string $state): void {
    if ($state === 'normal') {
        ($this->setBalance)(100000);
    } elseif ($state === 'stale') {
        Cache::put($this->key . ':attempt', ['at' => now()->timestamp, 'failed' => true], now()->addDay());
    } else {
        config(['sms.balance.push_enabled' => false]);
    }
    $this->artisan('sms:check-balance')->assertExitCode($state === 'stale' ? 1 : 0);
    Queue::assertNothingPushed();
})->with(['normal', 'stale', 'disabled']);

it('sends a private push at most once a day for a low balance episode', function (): void {
    $this->artisan('sms:check-balance')->assertSuccessful();
    $job = Queue::pushed(SendSmsBalanceWarning::class)->first();
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body, $data): bool => $token === 'private-token' && $title === 'SMS balansi kam qoldi' && str_contains($body, '5 000') && $data['type'] === 'sms_balance_low'
    )->andReturn('projects/test/messages/sms-1');
    $job->handle($this->balances, $this->sender);
    $job->handle($this->balances, $this->sender);
    $this->artisan('sms:check-balance')->assertSuccessful();
    Queue::pushed(SendSmsBalanceWarning::class)->last()->handle($this->balances, $this->sender);
});

it('does not send a second push on the same day when balance becomes empty', function (): void {
    $this->sender->shouldReceive('sendToToken')->once()->andReturn('projects/test/messages/sms-2');
    $this->artisan('sms:check-balance')->assertSuccessful();
    Queue::pushed(SendSmsBalanceWarning::class)->last()->handle($this->balances, $this->sender);
    ($this->setBalance)(0);
    $this->artisan('sms:check-balance')->assertSuccessful();
    $empty = Queue::pushed(SendSmsBalanceWarning::class)->last();
    $empty->handle($this->balances, $this->sender);
    $empty->handle($this->balances, $this->sender);
});

it('rechecks permissions, tokens, expiry and balance before delivering a queued push', function (string $change): void {
    $this->artisan('sms:check-balance')->assertSuccessful();
    $job = Queue::pushed(SendSmsBalanceWarning::class)->first();
    match ($change) {
        'permission' => $this->user->revokePermissionTo('view_sms_balance'),
        'revoked'    => $this->device->update(['revoked_at' => now()]),
        'token'      => $this->device->forceFill(['push_token_hash' => hash('sha256', 'rotated')])->save(),
        'expired'    => $this->device->token->forceFill(['expires_at' => now()->subSecond()])->save(),
        'recovery'   => ($this->setBalance)(50000),
        'stale'      => Cache::put($this->key . ':attempt', ['at' => now()->timestamp, 'failed' => true], now()->addDay()),
        'account'    => config(['sms.eskiz.email' => 'another@example.test']),
    };
    $this->sender->shouldNotReceive('sendToToken');
    $job->handle($this->balances, $this->sender);
})->with(['permission', 'revoked', 'token', 'expired', 'recovery', 'stale', 'account']);

it('does not send another alert after recovery and a later drop on the same day', function (): void {
    $this->sender->shouldReceive('sendToToken')->once()->andReturn('projects/test/messages/sms-3');
    $this->artisan('sms:check-balance')->assertSuccessful();
    $first = Queue::pushed(SendSmsBalanceWarning::class)->last();
    $first->handle($this->balances, $this->sender);
    ($this->setBalance)(50000);
    $this->artisan('sms:check-balance')->assertSuccessful();
    ($this->setBalance)(3000);
    $this->artisan('sms:check-balance')->assertSuccessful();
    $second = Queue::pushed(SendSmsBalanceWarning::class)->last();
    expect($second->notificationDate)->toBe($first->notificationDate);
    $second->handle($this->balances, $this->sender);
});

it('does not mark failed Firebase deliveries as sent', function (): void {
    $this->artisan('sms:check-balance')->assertSuccessful();
    $job = Queue::pushed(SendSmsBalanceWarning::class)->first();
    $this->sender->shouldReceive('sendToToken')->once()->andReturnNull();
    expect(fn () => $job->handle($this->balances, $this->sender))->toThrow(RuntimeException::class);
    expect(Cache::has($this->key . ':push:' . $job->notificationDate . ':' . $this->device->id))->toBeFalse();
});

it('does not queue a push when the balance is exactly twenty thousand', function (): void {
    ($this->setBalance)(20000);
    $this->artisan('sms:check-balance')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('sends for a balance just below twenty thousand', function (): void {
    ($this->setBalance)(19999);
    $this->sender->shouldReceive('sendToToken')->once()->andReturn('projects/test/messages/threshold');
    $this->artisan('sms:check-balance')->assertSuccessful();
    Queue::pushed(SendSmsBalanceWarning::class)->last()->handle($this->balances, $this->sender);
});

it('sends again at twenty hundred the next day even after a delayed previous delivery', function (): void {
    $this->sender->shouldReceive('sendToToken')->twice()->andReturn('projects/test/messages/daily');
    $this->artisan('sms:check-balance')->assertSuccessful();
    $first = Queue::pushed(SendSmsBalanceWarning::class)->last();
    $this->travel(7)->minutes();
    ($this->setBalance)(5000);
    $first->handle($this->balances, $this->sender);
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-22 20:00:00', 'Asia/Tashkent'));
    ($this->setBalance)(5000);
    $this->artisan('sms:check-balance')->assertSuccessful();
    $second = Queue::pushed(SendSmsBalanceWarning::class)->last();
    expect($second->notificationDate)->toBe('2026-11-22');
    $second->handle($this->balances, $this->sender);
});

it('does not deliver before twenty hundred or replay a previous days notification', function (string $time): void {
    $this->artisan('sms:check-balance')->assertSuccessful();
    $job = Queue::pushed(SendSmsBalanceWarning::class)->last();
    $this->travelTo(Illuminate\Support\Carbon::parse($time, 'Asia/Tashkent'));
    $this->sender->shouldNotReceive('sendToToken');
    $job->handle($this->balances, $this->sender);
})->with(['2026-11-21 19:59:00', '2026-11-22 00:00:00', '2026-11-22 20:00:00']);

it('schedules the daily balance warning at twenty hundred Tashkent time', function (): void {
    app(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'sms:check-balance'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 20 * * *')
        ->and($event->timezone)->toBe('Asia/Tashkent');
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-21 14:59:00', 'UTC'));
    expect($event->isDue(app()))->toBeFalse();
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-21 15:00:00', 'UTC'));
    expect($event->isDue(app()))->toBeTrue();
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-21 15:01:00', 'UTC'));
    expect($event->isDue(app()))->toBeFalse();
});
