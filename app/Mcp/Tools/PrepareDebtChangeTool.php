<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Illuminate\JsonSchema\JsonSchema;

class PrepareDebtChangeTool extends PosTool
{
    protected string $name = 'prepare_debt_change';

    protected string $description = 'Prepare only; DOES NOT modify a debt. Return approval_url for the human to approve in the application. create needs full_name, phone (+998...), amount and date. debt adds and payment subtracts amount. edit amount is the NEW TOTAL BALANCE (zero allowed), with optional debt date/note. Reuse request_id on retry. No automatic execution or SMS until approval.';

    protected function execute(Request $request): array
    {
        $operation = app(\App\Services\McpDebtService::class)->prepare(
            app(\App\Services\McpAccess::class)->connection(),
            app(\App\Services\McpAccess::class)->user(),
            $request->all(),
        );

        return app(\App\Services\McpReadService::class)->operationData($operation);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id'     => $schema->string()->required()->description('Fresh UUID per intended action; reuse for retries.'),
            'type'           => $schema->string()->enum(['create', 'debt', 'payment', 'edit'])->required(),
            'store_id'       => $schema->integer()->min(1)->description('Authorized store ID; omitted uses connection default.'),
            'debtor_id'      => $schema->integer()->min(1),
            'amount'         => $schema->integer()->min(0)->max(2147483647)->required(),
            'full_name'      => $schema->string()->max(150),
            'phone'          => $schema->string()->max(16),
            'date'           => $schema->string()->description('Debt date YYYY-MM-DD; required for create.'),
            'note'           => $schema->string()->max(500),
            'payment_method' => $schema->string()->enum(['cash', 'card']),
        ];
    }
}
