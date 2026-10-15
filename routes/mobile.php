<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureMobileStore;
use App\Http\Controllers\Mobile\DebtorController;
use App\Http\Controllers\Mobile\ProductController;
use App\Http\Controllers\Mobile\SessionController;

Route::prefix('mobile')->name('mobile.')->group(function (): void {
    Route::prefix('api/v1')->name('api.')->group(function (): void {
        Route::post('login', [SessionController::class, 'login'])->middleware('throttle:6,1')->name('login');
        Route::middleware('auth')->group(function (): void {
            Route::get('session', [SessionController::class, 'show'])->name('session');
            Route::post('store', [SessionController::class, 'switchStore'])->name('store');
            Route::post('logout', [SessionController::class, 'logout'])->name('logout');
            Route::middleware(EnsureMobileStore::class)->group(function (): void {
                Route::get('products', [ProductController::class, 'index'])->name('products');
                Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
                Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
                Route::get('debtors', [DebtorController::class, 'index'])->name('debtors');
                Route::post('debtors', [DebtorController::class, 'store'])->middleware('throttle:30,1')->name('debtors.store');
                Route::get('debtors/{debtor}', [DebtorController::class, 'show'])->name('debtors.show');
                Route::get('debtors/{debtor}/transactions', [DebtorController::class, 'transactions'])->name('debtors.transactions');
                Route::post('debtors/{debtor}/transactions', [DebtorController::class, 'transact'])->middleware('throttle:30,1')->name('debtors.transact');
            });
        });
    });
    Route::get('{path?}', fn () => response()->view('mobile.app')->header('Cache-Control', 'no-store, private'))
        ->where('path', '^(?!api(?:/|$)).*')->name('app');
});
