<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->mock(\App\Services\SmsService::class)->shouldReceive('sendSms')->andReturn(['success' => true]);
        $this->mock(\App\Services\TelegramNotifier::class)->shouldReceive('sendMessage')->andReturn(true);
        $this->mock(\App\Services\TelegramSaleNotifier::class)->shouldReceive('notify')->andReturnNull();
    }
}
