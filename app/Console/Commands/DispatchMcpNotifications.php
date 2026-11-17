<?php

namespace App\Console\Commands;

use App\Models\McpDelivery;
use Illuminate\Console\Command;
use App\Jobs\SendMcpNotification;

class DispatchMcpNotifications extends Command
{
    protected $signature = 'mcp:dispatch-notifications';

    protected $description = 'Dispatch unsent MCP notification outbox records';

    public function handle(): int
    {
        McpDelivery::query()->where('status', 'queued')->where('created_at', '<', now()->subMinute())
            ->orderBy('id')->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    SendMcpNotification::dispatch($delivery->id);
                }
            });
        McpDelivery::query()->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'finished_at' => now()]);
        $this->components->info('MCP notification queue checked.');

        return self::SUCCESS;
    }
}
