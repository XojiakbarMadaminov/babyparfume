<?php

namespace App\Http\Controllers;

use App\Services\McpAccess;
use App\Models\McpOperation;
use Illuminate\Http\Request;
use App\Models\McpConnection;
use App\Services\McpDebtService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class McpConnectionController extends Controller
{
    public function index(Request $request): View
    {
        $connections = McpConnection::query()->where('user_id', $request->user()->id)->with(['operations' => fn ($query) => $query->latest()->limit(20)])->latest()->get();
        $clients     = \Laravel\Passport\Client::query()->whereIn('id', $connections->pluck('client_id'))->pluck('name', 'id');

        return view('mcp.connections', ['connections' => $connections, 'clients' => $clients, 'stores' => $request->user()->stores()->get()]);
    }

    public function revoke(Request $request, McpConnection $connection, McpOAuthController $oauth): RedirectResponse
    {
        abort_unless((int) $connection->user_id === (int) $request->user()->id, 404);
        $connection->getConnection()->transaction(function () use ($connection, $oauth): void {
            $locked = McpConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->forceFill(['revoked_at' => now()])->save();
            $locked->operations()->where('status', 'pending')->update(['status' => 'cancelled']);
            $oauth->revokeTokens($locked->user_id, $locked->client_id);
        });

        return back()->with('status', 'MCP ulanishi bekor qilindi.');
    }

    public function defaultStore(\App\Http\Requests\McpDefaultStoreRequest $request, McpConnection $connection, McpAccess $access): RedirectResponse
    {
        $connection->getConnection()->transaction(function () use ($request, $connection, $access): void {
            $locked = McpConnection::query()->lockForUpdate()->findOrFail($connection->id);
            abort_if($locked->revoked_at, 403);
            $store = $access->store($locked, $request->user(), (int) $request->validated('store_id'));
            $locked->forceFill(['default_store_id' => $store->id])->save();
        });

        return back()->with('status', 'MCP asosiy do‘koni yangilandi. Tayyorlangan amallarning do‘koni o‘zgarmaydi.');
    }

    public function operation(Request $request, string $operation, McpAccess $access): View
    {
        $operation = McpOperation::query()->with(['connection', 'store', 'deliveries'])->findOrFail($operation);
        $access->permission($operation->connection, $request->user(), 'debts:write');
        $access->store($operation->connection, $request->user(), (int) $operation->store_id);
        $debtor = $operation->type === 'create' ? null : $access->debtor($operation->store, (int) $operation->payload['debtor_id']);
        $access->authorizeDebt($request->user(), $operation->type, $debtor);

        return view('mcp.operation', compact('operation', 'debtor'));
    }

    public function approve(\App\Http\Requests\McpDecisionRequest $request, string $operation, McpDebtService $debts): RedirectResponse
    {
        $operation = McpOperation::query()->findOrFail($operation);
        $debts->apply($request->user(), $operation);

        return redirect()->route('mcp.operations.show', $operation->id)->with('status', 'Amal bajarildi. Xabarnomalar navbatga qo‘yildi.');
    }

    public function reject(Request $request, string $operation): RedirectResponse
    {
        $operation = McpOperation::query()->whereHas('connection', fn ($query) => $query->where('user_id', $request->user()->id))->findOrFail($operation);
        McpOperation::query()->whereKey($operation->id)->where('status', 'pending')->update(['status' => 'rejected']);

        return redirect()->route('mcp.connections.index')->with('status', 'Amal rad etildi.');
    }
}
