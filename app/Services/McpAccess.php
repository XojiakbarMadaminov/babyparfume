<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Debtor;
use App\Models\McpConnection;
use Illuminate\Support\Facades\Gate;

class McpAccess
{
    public function connection(): McpConnection
    {
        $connection = request()->attributes->get('mcp_connection');
        abort_unless($connection instanceof McpConnection, 401);

        return $connection;
    }

    public function user(): User
    {
        $user = request()->attributes->get('mcp_user');
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function permission(McpConnection $connection, User $user, string $permission): void
    {
        abort_unless(config('mcp.enabled') && !$connection->revoked_at && (int) $connection->user_id === (int) $user->id, 403);
        abort_unless(in_array($permission, $connection->permissions, true), 403, 'MCP ulanishiga bu amalga ruxsat berilmagan.');
    }

    public function store(McpConnection $connection, User $user, ?int $storeId): Store
    {
        $storeId ??= $connection->default_store_id;
        abort_unless($storeId && in_array($storeId, array_map('intval', $connection->store_ids), true), 403, 'Avval ruxsat etilgan do‘konni tanlang.');

        return $user->stores()->whereKey($storeId)->firstOrFail();
    }

    public function debtor(Store $store, int $debtorId): Debtor
    {
        return Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->with('client')->findOrFail($debtorId);
    }

    public function authorizeDebt(User $user, string $type, ?Debtor $debtor = null): void
    {
        if ($type === 'create') {
            Gate::forUser($user)->authorize('create', Debtor::class);

            return;
        }

        abort_unless($debtor, 422);
        Gate::forUser($user)->authorize('view', $debtor);
        Gate::forUser($user)->authorize('update', $debtor);
    }
}
