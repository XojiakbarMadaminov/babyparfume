<?php

return [
    'enabled'                => (bool) env('FLUTTER_API_ENABLED', false),
    'protocol_version'       => 1,
    'token_lifetime_minutes' => 43200,
    'offline_max_seconds'    => 36000,
];
