<?php

use App\Services\SmsService;
use App\Services\SmsBalanceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;

beforeEach(function (): void {
    Cache::flush();
    config(['sms.eskiz.email' => 'sms@example.test', 'sms.eskiz.password' => 'private-password']);
    app()->instance(SmsService::class, new SmsService);
    Cache::put(app(SmsService::class)->tokenCacheKey(), 'cached-token', now()->addDay());
});

it('reads and caches the shared balance for five minutes', function (): void {
    Http::fake(['*/user/get-limit' => Http::response(['status' => 'success', 'data' => ['balance' => '125000']])]);
    $service = app(SmsBalanceService::class);
    expect($service->getBalance())->toMatchArray(['balance' => 125000.0, 'status' => 'normal', 'stale' => false, 'scope' => 'account']);
    $service->getBalance();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer cached-token'));
    $this->travel(301)->seconds();
    $service->getBalance();
    Http::assertSentCount(2);
});

it('uses the configured warning threshold and preserves negative balances', function (int $amount, string $status): void {
    config(['sms.balance.warning_threshold' => 20000]);
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['balance' => $amount]])]);
    expect(app(SmsBalanceService::class)->getBalance())->toMatchArray(['balance' => (float) $amount, 'status' => $status, 'stale' => false]);
})->with([[20001, 'normal'], [20000, 'normal'], [19999, 'low'], [1, 'low'], [0, 'empty'], [-2000, 'empty']]);

it('preserves the last known balance and timestamp when refresh fails', function (): void {
    Http::fake(['*' => Http::sequence()->push(['status' => 'success', 'data' => ['balance' => 15000]])->pushStatus(503)]);
    $service = app(SmsBalanceService::class);
    $first   = $service->getBalance();
    $this->travel(301)->seconds();
    $failed = $service->getBalance();
    expect($failed)->toMatchArray(['balance' => 15000.0, 'updated_at' => $first['updated_at'], 'stale' => true]);
    $service->getBalance();
    Http::assertSentCount(2);
});

it('never converts failed or malformed responses to a zero balance', function (mixed $data): void {
    Http::fake(['*' => Http::response($data)]);
    expect(app(SmsBalanceService::class)->getBalance())->toMatchArray(['balance' => null, 'updated_at' => null, 'stale' => true, 'status' => 'unavailable']);
})->with([
    'missing balance' => [['status' => 'success', 'data' => []]],
    'invalid balance' => [['status' => 'success', 'data' => ['balance' => 'unknown']]],
    'error status'    => [['status' => 'error', 'data' => ['balance' => 0]]],
    'html'            => ['<html>unavailable</html>'],
]);

it('handles connection failures and missing credentials without throwing', function (): void {
    Http::fake(fn () => throw new ConnectionException('offline'));
    expect(app(SmsBalanceService::class)->getBalance()['balance'])->toBeNull();
    config(['sms.eskiz.email' => null, 'sms.eskiz.password' => null]);
    expect(app(SmsBalanceService::class)->getBalance()['status'])->toBe('unavailable');
    Http::assertSentCount(0);
});

it('refreshes an expired token once before retrying the balance', function (): void {
    Http::fake([
        '*/user/get-limit' => Http::sequence()->pushStatus(401)->push(['status' => 'success', 'data' => ['balance' => 40000]]),
        '*/auth/login'     => Http::response(['data' => ['token' => 'new-token']]),
    ]);
    expect(app(SmsBalanceService::class)->getBalance()['balance'])->toBe(40000.0);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), 'get-limit') && $request->hasHeader('Authorization', 'Bearer new-token'));
});

it('allows manual refresh after a minute and prevents repeated provider calls', function (): void {
    Http::fake(['*' => Http::sequence()->push(['status' => 'success', 'data' => ['balance' => 10000]])->push(['status' => 'success', 'data' => ['balance' => 50000]])]);
    $service = app(SmsBalanceService::class);
    $service->getBalance();
    $service->getBalance(refresh: true);
    Http::assertSentCount(1);
    $this->travel(61)->seconds();
    expect($service->getBalance(refresh: true)['balance'])->toBe(50000.0);
    Http::assertSentCount(2);
});

it('does not reuse a balance from another Eskiz account', function (): void {
    Http::fake(['*' => Http::sequence()->push(['status' => 'success', 'data' => ['balance' => 10000]])->pushStatus(503)]);
    $service = app(SmsBalanceService::class);
    $service->getBalance();
    config(['sms.eskiz.email' => 'different@example.test']);
    expect($service->getBalance()['balance'])->toBeNull();
});
