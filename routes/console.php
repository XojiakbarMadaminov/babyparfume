<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('debtors:send-sms')->dailyAt('9:30')->timezone('Asia/Tashkent');
Schedule::command('debtors:send-daily-summary')->dailyAt('22:00')->timezone('Asia/Tashkent');
Schedule::command('sales:send-daily-summary')->dailyAt('22:00')->timezone('Asia/Tashkent');
