<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetOperationTool extends PosTool
{
    protected string $name = 'get_operation';

    protected string $description = 'Check the status of a proposal, the result and notification delivery statuses.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->operation((string) $request->get('operation_id'));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation_id' => $schema->string()->required(),
        ];
    }
}
