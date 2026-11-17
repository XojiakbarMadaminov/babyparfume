<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListTransactionsTool extends PosTool
{
    protected string $name = 'list_transactions';

    protected string $description = 'Paginated debt and payment history of one debtor in the selected store.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->transactions($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'store_id'  => $schema->integer()->min(1)->description('Authorized store ID; omitted uses connection default.'),
            'debtor_id' => $schema->integer()->min(1)->required(),
            'page'      => $schema->integer()->min(1)->max(1000),
            'per_page'  => $schema->integer()->min(1)->max(50),
        ];
    }
}
