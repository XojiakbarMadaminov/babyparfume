<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureFlutterStore;
use App\Http\Controllers\Flutter\SyncController;
use App\Http\Controllers\Flutter\LeaseController;
use App\Http\Controllers\Flutter\DebtorController;
use App\Http\Controllers\Flutter\DeviceController;
use App\Http\Middleware\AuthenticateFlutterDevice;
use App\Http\Controllers\Flutter\ProductController;
use App\Http\Controllers\Flutter\SessionController;
use App\Http\Controllers\Flutter\DebtorPhotoController;
use App\Http\Controllers\Flutter\CapabilitiesController;
use App\Http\Controllers\Flutter\SalesStatisticsController;

Route::get('health', CapabilitiesController::class)->middleware('throttle:60,1')->name('health');
Route::post('login', [SessionController::class, 'store'])->middleware('throttle:flutter-login')->name('login');

Route::middleware([AuthenticateFlutterDevice::class, 'throttle:flutter-api'])->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('session');
    Route::get('stores', function (Illuminate\Http\Request $request) {
        return response()->json([
            'data' => $request->user()->stores()->orderBy('stores.id')->get(['stores.id', 'stores.name'])
                ->map(fn ($store): array => ['id' => (string) $store->id, 'name' => $store->name]),
        ]);
    })->name('stores.index');
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereUuid('device')->name('devices.destroy');

    Route::middleware(EnsureFlutterStore::class)->prefix('stores/{store}')->name('stores.')->group(function (): void {
        Route::get('session', [SessionController::class, 'show'])->name('session');
        Route::post('lease', [LeaseController::class, 'store'])->name('lease');
        Route::get('statistics', SalesStatisticsController::class)->name('statistics');
        Route::get('clients', [App\Http\Controllers\Flutter\ClientController::class, 'index'])->name('clients.index');

        // Products
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/create-options', [ProductController::class, 'createOptions'])->name('products.create-options');
        Route::get('products/barcode', [ProductController::class, 'generateBarcode'])->name('products.barcode');
        Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('products/{product}/stock-in', [ProductController::class, 'receiveStock'])->name('products.stock-in');
        Route::match(['post', 'patch'], 'products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        // Debtors
        Route::get('debtors', [DebtorController::class, 'index'])->name('debtors.index');
        Route::post('debtors', [DebtorController::class, 'store'])->name('debtors.store');
        Route::get('debtors/{debtor}', [DebtorController::class, 'show'])->name('debtors.show');
        Route::match(['post', 'patch'], 'debtors/{debtor}', [DebtorController::class, 'update'])->name('debtors.update');
        Route::get('debtors/{debtor}/transactions', [DebtorController::class, 'transactions'])->name('debtors.transactions');
        Route::post('debtors/{debtor}/transactions', [DebtorController::class, 'transact'])->name('debtors.transact');
        Route::get('debtors/{debtor}/photo', DebtorPhotoController::class)->name('debtors.photo');

        // Sync & Offline Operations (PH-03)
        Route::post('bootstrap', [SyncController::class, 'bootstrap'])->name('bootstrap');
        Route::get('bootstrap/{snapshot}', [SyncController::class, 'bootstrapData'])->name('bootstrap.data');
        Route::get('delta', [SyncController::class, 'delta'])->name('delta');
        Route::post('operations', [SyncController::class, 'operations'])->name('operations');
        Route::get('operations/{operation}', [SyncController::class, 'showOperation'])->name('operations.show');
        Route::get('conflicts', [SyncController::class, 'conflicts'])->name('conflicts');
        Route::post('conflicts/{conflict}/resolve', [App\Http\Controllers\Flutter\ConflictController::class, 'update'])->whereNumber('conflict')->name('conflicts.resolve');
    });
});
