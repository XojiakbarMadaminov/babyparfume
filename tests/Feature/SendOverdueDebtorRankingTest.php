<?php

use App\Models\Client;
use App\Models\Debtor;
use App\Models\DebtorTransaction;
use App\Models\Store;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('ranks debtors based on days since last payment or initial debt date', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');

    $store = Store::create([
        'name' => 'Bosh Do\'kon',
        'address' => 'Toshkent',
        'phone' => '+998901234567',
    ]);

    // Client 1: Borrowed 365 days ago, but paid 5 days ago (Overdue = 5 days)
    $clientRecentPayer = Client::create([
        'full_name' => 'Ali Valiyev',
        'phone' => '+998901111111',
    ]);
    $debtorRecentPayer = Debtor::create([
        'store_id' => $store->id,
        'client_id' => $clientRecentPayer->id,
        'amount' => 500000,
        'currency' => 'UZS',
        'date' => Carbon::now()->subDays(365),
    ]);
    DebtorTransaction::create([
        'debtor_id' => $debtorRecentPayer->id,
        'amount' => 1000000,
        'type' => 'debt',
        'date' => Carbon::now()->subDays(365),
    ]);
    DebtorTransaction::create([
        'debtor_id' => $debtorRecentPayer->id,
        'amount' => 500000,
        'type' => 'payment',
        'date' => Carbon::now()->subDays(5),
    ]);

    // Client 2: Borrowed 100 days ago, never made any payment (Overdue = 100 days)
    $clientNeverPaid = Client::create([
        'full_name' => 'Olim Qodirov',
        'phone' => '+998902222222',
    ]);
    $debtorNeverPaid = Debtor::create([
        'store_id' => $store->id,
        'client_id' => $clientNeverPaid->id,
        'amount' => 800000,
        'currency' => 'UZS',
        'date' => Carbon::now()->subDays(100),
    ]);
    DebtorTransaction::create([
        'debtor_id' => $debtorNeverPaid->id,
        'amount' => 800000,
        'type' => 'debt',
        'date' => Carbon::now()->subDays(100),
    ]);

    // Client 3: Borrowed 200 days ago, last payment 50 days ago (Overdue = 50 days)
    $clientOldPayer = Client::create([
        'full_name' => 'Salim Karimov',
        'phone' => '+998903333333',
    ]);
    $debtorOldPayer = Debtor::create([
        'store_id' => $store->id,
        'client_id' => $clientOldPayer->id,
        'amount' => 300000,
        'currency' => 'UZS',
        'date' => Carbon::now()->subDays(200),
    ]);
    DebtorTransaction::create([
        'debtor_id' => $debtorOldPayer->id,
        'amount' => 600000,
        'type' => 'debt',
        'date' => Carbon::now()->subDays(200),
    ]);
    DebtorTransaction::create([
        'debtor_id' => $debtorOldPayer->id,
        'amount' => 300000,
        'type' => 'payment',
        'date' => Carbon::now()->subDays(50),
    ]);

    // Client 4: Paid off fully (amount = 0) -> should be excluded
    $clientZeroDebt = Client::create([
        'full_name' => 'Botir Zokirov',
        'phone' => '+998904444444',
    ]);
    $debtorZero = Debtor::create([
        'store_id' => $store->id,
        'client_id' => $clientZeroDebt->id,
        'amount' => 0,
        'currency' => 'UZS',
        'date' => Carbon::now()->subDays(300),
    ]);

    $capturedMessage = null;
    $telegramMock = mock(TelegramNotifier::class);
    $telegramMock->shouldReceive('sendMessage')
        ->once()
        ->withArgs(function ($msg) use (&$capturedMessage) {
            $capturedMessage = $msg;

            return true;
        })
        ->andReturn(true);

    $this->app->instance(TelegramNotifier::class, $telegramMock);

    $this->artisan('debtors:send-overdue-ranking')
        ->assertSuccessful();

    expect($capturedMessage)->not->toBeNull()
        ->and($capturedMessage)->toContain('ENG KO\'P VAQTDAN BERI TO\'LANMAGAN QARZDORLAR')
        ->and($capturedMessage)->toContain('Bosh Do\'kon')
        ->and($capturedMessage)->toContain('Olim Qodirov')
        ->and($capturedMessage)->toContain('Salim Karimov')
        ->and($capturedMessage)->toContain('Ali Valiyev')
        ->and($capturedMessage)->not->toContain('Botir Zokirov');

    // Olim (100 days) must appear before Salim (50 days), and Salim before Ali (5 days)
    $posOlim = strpos($capturedMessage, 'Olim Qodirov');
    $posSalim = strpos($capturedMessage, 'Salim Karimov');
    $posAli = strpos($capturedMessage, 'Ali Valiyev');

    expect($posOlim)->toBeLessThan($posSalim);
    expect($posSalim)->toBeLessThan($posAli);

    Carbon::setTestNow();
});

it('handles empty debtors gracefully', function () {
    Store::create([
        'name' => 'Bo\'sh Do\'kon',
        'address' => 'Toshkent',
        'phone' => '+998901112233',
    ]);

    $telegramMock = mock(TelegramNotifier::class);
    $telegramMock->shouldReceive('sendMessage')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'Qarzdorlar topilmadi.'))
        ->andReturn(true);

    $this->app->instance(TelegramNotifier::class, $telegramMock);

    $this->artisan('debtors:send-overdue-ranking')
        ->assertSuccessful();
});

it('respects limit option', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');

    $store = Store::create([
        'name' => 'Filial 1',
        'address' => 'Samarqand',
        'phone' => '+998901234568',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $client = Client::create([
            'full_name' => "Mijoz {$i}",
            'phone' => "+99890000000{$i}",
        ]);
        Debtor::create([
            'store_id' => $store->id,
            'client_id' => $client->id,
            'amount' => 100000 * $i,
            'currency' => 'UZS',
            'date' => Carbon::now()->subDays($i * 10),
        ]);
    }

    $capturedMessage = null;
    $telegramMock = mock(TelegramNotifier::class);
    $telegramMock->shouldReceive('sendMessage')
        ->once()
        ->withArgs(function ($msg) use (&$capturedMessage) {
            $capturedMessage = $msg;

            return true;
        })
        ->andReturn(true);

    $this->app->instance(TelegramNotifier::class, $telegramMock);

    $this->artisan('debtors:send-overdue-ranking', ['--limit' => 2])
        ->assertSuccessful();

    // Only top 2 oldest debtors (Mijoz 5 = 50 days, Mijoz 4 = 40 days) should be in list
    expect($capturedMessage)->toContain('Mijoz 5')
        ->and($capturedMessage)->toContain('Mijoz 4')
        ->and($capturedMessage)->not->toContain('Mijoz 3')
        ->and($capturedMessage)->not->toContain('Mijoz 2')
        ->and($capturedMessage)->not->toContain('Mijoz 1');

    Carbon::setTestNow();
});

