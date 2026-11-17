<?php

return [
    'enabled'              => (bool) env('MCP_ENABLED', false),
    'writes_enabled'       => (bool) env('MCP_WRITES_ENABLED', false),
    'registration_enabled' => (bool) env('MCP_REGISTRATION_ENABLED', true),
    'access_token_minutes' => 60,
    'refresh_token_days'   => 30,
    'approval_minutes'     => 15,
    'allowed_origins'      => [],
    'permissions'          => ['products:read', 'debts:read', 'sales:read', 'debts:write'],
];
