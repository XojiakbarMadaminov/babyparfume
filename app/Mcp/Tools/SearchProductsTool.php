<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchProductsTool extends PosTool
{
    protected string $name = 'search_products';

    protected string $description = 'Search products and stock only in the selected authorized store.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->products($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'store_id' => $schema->integer()->min(1)->description('Authorized store ID; omitted uses connection default.'),
            'search'   => $schema->string()->max(100)->description('Search by name, phone or barcode.'),
            'page'     => $schema->integer()->min(1)->max(1000),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }
}
