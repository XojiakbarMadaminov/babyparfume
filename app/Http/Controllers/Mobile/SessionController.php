<?php

namespace App\Http\Controllers\Mobile;

use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\Mobile\LoginRequest;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Mobile\SwitchStoreRequest;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user   = $request->user();
        $stores = $user->stores()->get(['stores.id', 'stores.name']);

        return response()->json([
            'user'        => ['name' => $user->name],
            'stores'      => $stores->map(fn ($store): array => ['id' => $store->id, 'name' => $store->name]),
            'store_id'    => $stores->contains('id', $user->current_store_id) ? $user->current_store_id : null,
            'permissions' => [
                'products'      => Gate::allows('viewAny', Product::class),
                'debtors'       => Gate::allows('viewAny', Debtor::class),
                'create_debtor' => Gate::allows('create', Debtor::class),
            ],
        ])->header('Cache-Control', 'no-store, private')->header('X-CSRF-TOKEN', $request->session()->token());
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->validated())) {
            throw ValidationException::withMessages(['email' => 'Email yoki parol noto‘g‘ri.']);
        }
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function switchStore(SwitchStoreRequest $request): JsonResponse
    {
        $store = $request->user()->stores()->findOrFail($request->integer('store_id'));
        $request->user()->update(['current_store_id' => $store->id]);
        cache()->forget('active_stocks_for_store_' . $request->user()->id);

        return $this->show($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Hisobdan chiqildi.'])->header('Cache-Control', 'no-store');
    }
}
