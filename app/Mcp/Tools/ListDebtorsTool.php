<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDebtorsTool extends PosTool
{
    protected string $name = 'list_debtors';

    protected string $description = 'Find debtors by name or phone within the selected store. Ask when multiple people match.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->debtors($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'store_id' => $schema->integer()->min(1)->description('Authorized store ID; omitted uses connection default.'),
            'search'   => $schema->string()->max(100)->description('Search by name, phone or barcode.'),
            'status'   => $schema->string()->enum(['active', 'paid', 'overdue']),
            'page'     => $schema->integer()->min(1)->max(1000),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }
}
