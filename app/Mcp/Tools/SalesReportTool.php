<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SalesReportTool extends PosTool
{
    protected string $name = 'get_sales_report';

    protected string $description = 'Sales report for up to 366 days. Cost and profit require an additional permission.';

    protected function execute(Request $request): array
    {
        return app(\App\Services\McpReadService::class)->sales($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'store_id' => $schema->integer()->min(1)->description('Authorized store ID; omitted uses connection default.'),
            'start'    => $schema->string()->required()->description('Start date YYYY-MM-DD'),
            'end'      => $schema->string()->required()->description('End date YYYY-MM-DD, no later than today'),
        ];
    }
}
