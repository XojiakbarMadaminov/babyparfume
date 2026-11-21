<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\FlutterDevice;
use Illuminate\Support\Carbon;
use App\Models\DebtorTransaction;
use App\Services\TelegramNotifier;
use App\Services\SmsBalanceService;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendDailyDebtorSummaryPush;
use Spatie\Permission\Models\Permission;
use App\Services\DailyDebtorSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-21 22:00:00', 'Asia/Tashkent'));
    Queue::fake();
    Cache::flush();
    $this->storeA = Store::query()->create(['name' => 'Do‘kon A', 'address' => 'Toshkent', 'phone' => '+998901111111']);
    $this->storeB = Store::query()->create(['name' => 'Do‘kon B', 'address' => 'Toshkent', 'phone' => '+998902222222']);
    $this->user   = User::factory()->create(['current_store_id' => $this->storeA->id]);
    $this->user->stores()->attach([$this->storeA->id, $this->storeB->id]);
    $this->user->givePermissionTo(Permission::findOrCreate('ViewAny:Debtor', 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('view_sms_balance', 'web'));
    $token        = $this->user->createToken('flutter:daily-report', ['flutter:access'], now()->addDays(3));
    $this->device = FlutterDevice::factory()->create(['user_id' => $this->user->id, 'personal_access_token_id' => $token->accessToken->id]);
    $this->device->forceFill(['push_token' => 'daily-private-token', 'push_token_hash' => hash('sha256', 'daily-private-token')])->save();
    $this->sender = $this->mock(FirebasePushSender::class);
    $this->sender->shouldReceive('isConfigured')->andReturnTrue()->byDefault();
    $this->balanceSnapshot = ['balance' => 15000.0, 'stale' => false];
    $this->balances        = $this->mock(SmsBalanceService::class);
    $this->balances->shouldReceive('getBalance')->andReturnUsing(fn (): array => $this->balanceSnapshot)->byDefault();
    $this->summaries      = app(DailyDebtorSummaryService::class);
    $this->addTransaction = function (Store $store, string $type, int $amount, string $currency = 'UZS', ?string $date = null): void {
        $client = Client::query()->create(['full_name' => fake()->name(), 'phone' => '+99890' . fake()->unique()->numerify('#######')]);
        $debtor = Debtor::withoutEvents(fn () => Debtor::query()->create([
            'store_id' => $store->id, 'client_id' => $client->id, 'amount' => $amount, 'currency' => $currency, 'date' => '2026-11-21',
        ]));
        DebtorTransaction::query()->create(['debtor_id' => $debtor->id, 'type' => $type, 'amount' => $amount, 'date' => $date ?? '2026-11-21 12:00:00']);
    };
    ($this->addTransaction)($this->storeA, 'debt', 100000);
    ($this->addTransaction)($this->storeA, 'debt', 250000);
    ($this->addTransaction)($this->storeA, 'payment', 50000);
    ($this->addTransaction)($this->storeA, 'debt', 100, 'USD');
    ($this->addTransaction)($this->storeA, 'payment', 20, 'USD');
    ($this->addTransaction)($this->storeB, 'debt', 900000);
    ($this->addTransaction)($this->storeB, 'payment', 300000);
    ($this->addTransaction)($this->storeB, 'debt', 777777, 'UZS', '2026-11-20 23:59:59');
    ($this->addTransaction)($this->storeB, 'debt', 888888, 'UZS', '2026-11-22 00:00:00');
});

it('uses the same daily totals with the emoji layout and SMS balance for the APK', function (): void {
    $telegramBody = null;
    $telegram     = Mockery::mock(TelegramNotifier::class);
    $telegram->shouldReceive('sendMessage')->once()->withArgs(function (string $body) use (&$telegramBody): bool {
        $telegramBody = $body;

        return str_contains($body, 'KUNLIK QARZDORLIK: 21.11.2026')
            && str_contains($body, '350 000 UZS') && str_contains($body, '50 000 UZS')
            && str_contains($body, '100 USD') && str_contains($body, '20 USD')
            && str_contains($body, '900 000 UZS') && str_contains($body, '300 000 UZS')
            && !str_contains($body, '777 777') && !str_contains($body, '888 888');
    })->andReturnTrue();
    app()->instance(TelegramNotifier::class, $telegram);
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::assertPushed(SendDailyDebtorSummaryPush::class, 1);
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(function ($token, $title, $body, $data): bool {
        return $token === 'daily-private-token'
            && str_contains($body, '🏪 Do‘kon: Do‘kon A')
            && str_contains($body, '📊 Bugungi qarzdorlik: 350 000 so‘m')
            && str_contains($body, '💳 Bugungi to‘lovlar: 50 000 so‘m')
            && str_contains($body, '📊 Bugungi qarzdorlik: 100 USD')
            && str_contains($body, '💳 Bugungi to‘lovlar: 20 USD')
            && str_contains($body, '🏪 Do‘kon: Do‘kon B')
            && str_contains($body, '📊 Bugungi qarzdorlik: 900 000 so‘m')
            && str_contains($body, '💰 SMS balansi: 15 000 so‘m')
            && $title === 'Kunlik qarzdorlik: 21.11.2026' && $data['type'] === 'daily_debtor_summary';
    })->andReturn('projects/test/messages/daily');
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    $job->handle($this->sender, $this->summaries);
    $job->handle($this->sender, $this->summaries);
});

it('preserves the existing Telegram layout and empty day text', function (): void {
    $totals = [$this->storeA->id => ['name' => 'Do‘kon A', 'data' => ['UZS' => ['debt' => 350000, 'payment' => 50000]]]];
    expect($this->summaries->message('2026-11-21', $totals))->toBe(
        str_replace("\n", PHP_EOL, "KUNLIK QARZDORLIK: 21.11.2026\n\nDo'kon: Do‘kon A\nUZS:\n  - Qarzdorlik: 350 000 UZS\n  - To'lov: 50 000 UZS")
    );
    expect($this->summaries->message('2026-11-21', []))->toBe("Kunlik hisobot 21.11.2026\nBugun yangi qarz yoki to'lov qayd etilmadi.");
});

it('formats the selected four line notification layout exactly', function (): void {
    $totals = [$this->storeA->id => ['name' => 'MILLION PARFUME', 'data' => ['UZS' => ['debt' => 1250000, 'payment' => 750000]]]];
    expect($this->summaries->pushMessage($totals, $this->balanceSnapshot))->toBe(
        "🏪 Do‘kon: MILLION PARFUME\n📊 Bugungi qarzdorlik: 1 250 000 so‘m\n💳 Bugungi to‘lovlar: 750 000 so‘m\n💰 SMS balansi: 15 000 so‘m"
    );
});

it('marks unavailable or stale SMS balances without hiding the debt report', function (?float $amount, bool $stale, string $text): void {
    $this->balanceSnapshot = ['balance' => $amount, 'stale' => $stale];
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body): bool => str_contains($body, $text) && str_contains($body, '📊 Bugungi qarzdorlik: 350 000 so‘m')
    )->andReturn('projects/test/messages/sms-state');
    Queue::pushed(SendDailyDebtorSummaryPush::class)->first()->handle($this->sender, $this->summaries);
})->with([
    [null, true, '💰 SMS balansi: ma’lumot olinmadi'],
    [15000.0, true, '💰 SMS balansi: 15 000 so‘m (oxirgi ma’lumot)'],
    [0.0, false, '💰 SMS balansi: 0 so‘m'],
]);

it('omits the global SMS balance when permission is absent or revoked', function (bool $revokeAfterQueue): void {
    if (!$revokeAfterQueue) {
        $this->user->revokePermissionTo('view_sms_balance');
        $this->balances->shouldNotReceive('getBalance');
    }
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    if ($revokeAfterQueue) {
        $this->user->revokePermissionTo('view_sms_balance');
    } else {
        expect($job->smsBalance)->toBeNull();
    }
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body): bool => str_contains($body, '📊 Bugungi qarzdorlik:') && !str_contains($body, 'SMS balansi:')
    )->andReturn('projects/test/messages/without-sms');
    $job->handle($this->sender, $this->summaries);
})->with([false, true]);

it('captures the shared SMS balance once for all devices', function (): void {
    $token = $this->user->createToken('flutter:second-device', ['flutter:access'], now()->addDay());
    FlutterDevice::factory()->create(['user_id' => $this->user->id, 'personal_access_token_id' => $token->accessToken->id])
        ->forceFill(['push_token' => 'second-token', 'push_token_hash' => hash('sha256', 'second-token')])->save();
    $this->balances->shouldReceive('getBalance')->once()->andReturn($this->balanceSnapshot);
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::assertPushed(SendDailyDebtorSummaryPush::class, 2);
    foreach (Queue::pushed(SendDailyDebtorSummaryPush::class) as $job) {
        expect($job->smsBalance)->toBe($this->balanceSnapshot);
    }
});

it('scopes the queued report to the recipients authorized stores', function (): void {
    $this->user->stores()->detach($this->storeB);
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    expect(array_keys($job->totals))->toBe([$this->storeA->id]);
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body): bool => str_contains($body, 'Do‘kon A') && !str_contains($body, 'Do‘kon B') && !str_contains($body, '900 000')
    )->andReturn('projects/test/messages/scoped');
    $job->handle($this->sender, $this->summaries);
});

it('removes stores revoked after the job was queued', function (): void {
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $this->user->stores()->detach($this->storeB);
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body): bool => str_contains($body, 'Do‘kon A') && !str_contains($body, 'Do‘kon B')
    )->andReturn('projects/test/messages/rechecked');
    Queue::pushed(SendDailyDebtorSummaryPush::class)->first()->handle($this->sender, $this->summaries);
});

it('does not queue reports for users without debtor access or assigned stores', function (string $change): void {
    if ($change === 'permission') {
        $this->user->revokePermissionTo('ViewAny:Debtor');
    } else {
        $this->user->stores()->detach();
    }
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::assertNothingPushed();
})->with(['permission', 'stores']);

it('rechecks device, permission and store access before sending', function (string $change): void {
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    match ($change) {
        'permission' => $this->user->revokePermissionTo('ViewAny:Debtor'),
        'stores'     => $this->user->stores()->detach(),
        'revoked'    => $this->device->update(['revoked_at' => now()]),
        'rotated'    => $this->device->forceFill(['push_token_hash' => hash('sha256', 'new-token')])->save(),
        'expired'    => $this->device->token->forceFill(['expires_at' => now()->subSecond()])->save(),
    };
    $this->sender->shouldNotReceive('sendToToken');
    $job->handle($this->sender, $this->summaries);
})->with(['permission', 'stores', 'revoked', 'rotated', 'expired']);

it('still queues APK reports when Telegram fails', function (bool $throws): void {
    $telegram    = Mockery::mock(TelegramNotifier::class);
    $expectation = $telegram->shouldReceive('sendMessage')->once();
    if ($throws) {
        $expectation->andThrow(new RuntimeException('Telegram unavailable'));
    } else {
        $expectation->andReturnFalse();
    }
    app()->instance(TelegramNotifier::class, $telegram);
    $this->artisan('debtors:send-daily-summary')->assertFailed();
    Queue::assertPushed(SendDailyDebtorSummaryPush::class, 1);
})->with([true, false]);

it('keeps Telegram working when Firebase is not configured', function (): void {
    $this->sender->shouldReceive('isConfigured')->andReturnFalse();
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('sends the empty day message to the APK too', function (): void {
    $this->travelTo(Carbon::parse('2026-11-23 22:00:00', 'Asia/Tashkent'));
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $this->sender->shouldReceive('sendToToken')->once()->withArgs(
        fn ($token, $title, $body): bool => $body === "📊 Bugun yangi qarz yoki to‘lov qayd etilmadi.\n💰 SMS balansi: 15 000 so‘m"
    )->andReturn('projects/test/messages/empty');
    Queue::pushed(SendDailyDebtorSummaryPush::class)->first()->handle($this->sender, $this->summaries);
});

it('sends one report per calendar day even if the command is rerun', function (): void {
    $this->sender->shouldReceive('sendToToken')->twice()->andReturn('projects/test/messages/daily-once');
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::pushed(SendDailyDebtorSummaryPush::class)->last()->handle($this->sender, $this->summaries);
    ($this->addTransaction)($this->storeA, 'debt', 10000);
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::pushed(SendDailyDebtorSummaryPush::class)->last()->handle($this->sender, $this->summaries);
    $this->travelTo(Carbon::parse('2026-11-22 22:00:00', 'Asia/Tashkent'));
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::pushed(SendDailyDebtorSummaryPush::class)->last()->handle($this->sender, $this->summaries);
});

it('does not deliver the report before twenty two hundred or on another date', function (string $time): void {
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    $this->travelTo(Carbon::parse($time, 'Asia/Tashkent'));
    $this->sender->shouldNotReceive('sendToToken');
    $job->handle($this->sender, $this->summaries);
})->with(['2026-11-21 21:59:00', '2026-11-22 00:00:00']);

it('splits long reports without losing text or breaking UTF8', function (): void {
    $text  = str_repeat("🏪 Do‘kon: O‘zbekiston\n📊 Bugungi qarzdorlik: 350 000 so‘m\n💳 Bugungi to‘lovlar: 50 000 so‘m\n", 100);
    $parts = $this->summaries->notificationParts($text);
    expect(count($parts))->toBeGreaterThan(1)->and(implode('', $parts))->toBe($text);
    foreach ($parts as $part) {
        expect(strlen($part))->toBeLessThanOrEqual(2000)->and(mb_check_encoding($part, 'UTF-8'))->toBeTrue();
    }
});

it('retries failed deliveries without marking them as sent', function (): void {
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    $job = Queue::pushed(SendDailyDebtorSummaryPush::class)->first();
    $this->sender->shouldReceive('sendToToken')->once()->andReturnNull();
    expect(fn () => $job->handle($this->sender, $this->summaries))->toThrow(RuntimeException::class);
    expect(Cache::has('daily_debtor_summary:2026-11-21:' . $this->device->id))->toBeFalse();
});

it('resumes a multipart report without sending accepted parts again', function (): void {
    $totals = [];
    for ($index = 0; $index < 25; $index++) {
        $store = Store::query()->create(['name' => str_repeat('Do‘kon ', 15) . $index, 'address' => 'Toshkent', 'phone' => '+998901111111']);
        $this->user->stores()->attach($store);
        $totals[$store->id] = ['name' => $store->name, 'data' => ['UZS' => ['debt' => 350000, 'payment' => 50000]]];
    }
    $parts  = $this->summaries->notificationParts($this->summaries->pushMessage($totals));
    $bodies = [];
    $this->sender->shouldReceive('sendToToken')->times(count($parts) + 1)->andReturnUsing(function ($token, $title, $body) use (&$bodies): ?string {
        $bodies[] = $body;

        return count($bodies) === 2 ? null : 'projects/test/messages/part';
    });
    $job = new SendDailyDebtorSummaryPush($this->device->id, $this->device->push_token_hash, '2026-11-21', $totals);
    expect(fn () => $job->handle($this->sender, $this->summaries))->toThrow(RuntimeException::class);
    $job->handle($this->sender, $this->summaries);
    $job->handle($this->sender, $this->summaries);
    expect(count(array_filter($bodies, fn (string $body): bool => $body === $parts[0])))->toBe(1);
    expect(Cache::has('daily_debtor_summary:2026-11-21:' . $this->device->id))->toBeTrue();
});

it('uses private Firebase tokens and the account notification channel', function (): void {
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
    config(['services.firebase_push.project_id' => 'oson-pos-test']);
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/private-report'])]);
    $this->artisan('debtors:send-daily-summary')->assertSuccessful();
    Queue::pushed(SendDailyDebtorSummaryPush::class)->first()->handle($sender, $this->summaries);
    Http::assertSent(fn ($request): bool => $request['message']['token'] === 'daily-private-token'
        && !isset($request['message']['topic'])
        && $request['message']['data']['type'] === 'daily_debtor_summary'
        && $request['message']['android']['notification']['channel_id'] === FirebasePushSender::PRIVATE_CHANNEL);
});

it('keeps the Telegram and APK report on the same twenty two hundred schedule', function (): void {
    app(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());
    $event  = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'debtors:send-daily-summary'));
    expect($event->expression)->toBe('0 22 * * *')->and($event->timezone)->toBe('Asia/Tashkent');
    $this->travelTo(Carbon::parse('2026-11-21 17:00:00', 'UTC'));
    expect($event->isDue(app()))->toBeTrue();
});
