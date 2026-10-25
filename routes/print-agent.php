<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Controllers\Printing\AgentController;

Route::post('pair', [AgentController::class, 'pair'])->middleware('throttle:6,1')->name('pair');
Route::middleware([AuthenticatePrintAgent::class, 'throttle:180,1'])->group(function (): void {
    Route::post('heartbeat', [AgentController::class, 'heartbeat'])->name('heartbeat');
    Route::post('jobs/claim', [AgentController::class, 'claim'])->name('claim');
    Route::get('jobs/{jobId}', [AgentController::class, 'show'])->whereUuid('jobId')->name('jobs.show');
    Route::get('jobs/{jobId}/document', [AgentController::class, 'document'])->whereUuid('jobId')->name('jobs.document');
    Route::post('jobs/{jobId}/events', [AgentController::class, 'event'])->whereUuid('jobId')->name('jobs.events');
});
