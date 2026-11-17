<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AccountTool extends PosTool
{
    protected string $name = 'get_account';

    protected string $description = 'Account, authorized stores and the connection default store. No write.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->account();
    }

    public function schema(JsonSchema $schema): array
    {
        return [

        ];
    }
}
