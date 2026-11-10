<?php

use App\Http\Controllers\CsmsAuthController;
use App\Http\Controllers\CsmsPortalController;
use App\Http\Middleware\CsmsAccess;
use Illuminate\Support\Facades\Route;

Route::view('/csms', 'csms.portal')->name('csms.portal');
Route::post('/csms/internal-session', [CsmsAuthController::class, 'internal'])->middleware(['auth', 'account.access', "can:access-module,'csms'"])->name('csms.internal');
Route::prefix('csms/api')->group(function (): void {
    Route::post('/login', [CsmsAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/register', [CsmsAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::middleware(CsmsAccess::class)->group(function (): void {
        Route::get('/state', [CsmsPortalController::class, 'state']);
        Route::post('/logout', [CsmsAuthController::class, 'logout']);
        Route::put('/password', [CsmsAuthController::class, 'password'])->middleware('throttle:6,1');
        Route::post('/vendors', [CsmsPortalController::class, 'profile']);
        Route::put('/vendors/{vendor}', [CsmsPortalController::class, 'profile']);
        Route::post('/vendors/{vendor}/answers', [CsmsPortalController::class, 'answers']);
        Route::post('/vendors/{vendor}/evidence', [CsmsPortalController::class, 'upload'])->middleware('throttle:120,1');
        Route::post('/vendors/{vendor}/evidence/chunks', [CsmsPortalController::class, 'chunks'])->middleware('throttle:240,1');
        Route::get('/evidence/{id}', [CsmsPortalController::class, 'download']);
        Route::post('/vendors/{vendor}/assessments/{stage}', [CsmsPortalController::class, 'assessment']);
        Route::post('/accounts', [CsmsPortalController::class, 'account']);
        Route::put('/accounts/{user}/password', [CsmsPortalController::class, 'resetPassword'])->middleware('throttle:6,1');
        Route::put('/accounts/{user}/roles', [CsmsPortalController::class, 'roles']);
        Route::delete('/accounts/{user}', [CsmsPortalController::class, 'deactivate']);
        Route::get('/export', [CsmsPortalController::class, 'export']);
    });
});
