<?php

return [

    'eskiz' => [
        'email'    => env('ESKIZ_EMAIL'),
        'password' => env('ESKIZ_PASSWORD'),
        'text'     => 'Bu Eskiz dan test',
    ],

    'balance' => [
        'warning_threshold' => (float) env('SMS_BALANCE_WARNING_THRESHOLD', 20000),
        'cache_seconds'     => 300,
        'retry_seconds'     => 60,
        'push_enabled'      => (bool) env('SMS_BALANCE_PUSH_ENABLED', true),
    ],
];
