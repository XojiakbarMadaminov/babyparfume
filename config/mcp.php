<?php

return [
    'enabled'              => (bool) env('MCP_ENABLED', false),
    'writes_enabled'       => (bool) env('MCP_WRITES_ENABLED', false),
    'registration_enabled' => (bool) env('MCP_REGISTRATION_ENABLED', true),
    'access_token_minutes' => 60,
    'refresh_token_days'   => 30,
    'approval_minutes'     => 15,
    'allowed_origins'      => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', 'https://chatgpt.com,https://chat.openai.com'))))),
    'permissions'          => ['products:read', 'debts:read', 'sales:read', 'debts:write'],
];
