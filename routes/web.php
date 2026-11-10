<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\DocumentControlController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PolicyDocumentController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home'])->name('home');
Route::middleware('guest')->group(function (): void {
    Route::get('/forgot-password', [ForgotPasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'reset'])->where('token', '[A-Za-z0-9]{64}')->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.store');

    Route::get('/setup', [SetupController::class, 'create'])->name('setup.create');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:setup')->name('setup.store');
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});
Route::middleware(['auth', 'account.access'])->group(function (): void {
    Route::get('/dashboard', [WorkspaceController::class, 'show'])->name('dashboard');
    Route::middleware("can:access-module,'communication'")->prefix('communication')->name('communication.')->group(function (): void {
        Route::get('/', [CommunicationController::class, 'index'])->name('index');
        Route::post('/', [CommunicationController::class, 'store'])->name('store');
        Route::get('/{matrix}', [CommunicationController::class, 'show'])->name('show');
        Route::post('/{matrix}/actions', [CommunicationController::class, 'action'])->middleware('throttle:60,1')->name('action');
        Route::post('/{matrix}/evidence/chunks', [CommunicationController::class, 'chunks'])->middleware('throttle:240,1')->name('chunks');
        Route::get('/{matrix}/evidence/{evidence}', [CommunicationController::class, 'file'])->name('file');
    });
    Route::delete('/policy/{document}', [PolicyDocumentController::class, 'destroy'])->middleware("can:access-module,'policy'")->name('policy.destroy');
    Route::middleware("can:access-module,'documents'")->prefix('document-control')->name('document-control.')->group(function (): void {
        Route::get('/', [DocumentControlController::class, 'index'])->name('index');
        Route::get('/create', [DocumentControlController::class, 'create'])->name('create');
        Route::get('/attachments/{attachment}/file', [DocumentAttachmentController::class, 'file'])->name('attachment.file');
        Route::delete('/attachments/{attachment}', [DocumentAttachmentController::class, 'destroy'])->name('attachment.destroy');
        Route::post('/', [DocumentControlController::class, 'store'])->name('store');
        Route::get('/{document}/edit', [DocumentControlController::class, 'edit'])->name('edit');
        Route::put('/{document}', [DocumentControlController::class, 'update'])->name('update');
        Route::post('/{document}/actions', [DocumentControlController::class, 'action'])->middleware('throttle:12,1')->name('action');
        Route::get('/{document}/published', [DocumentControlController::class, 'published'])->name('published');
        Route::get('/{document}', [DocumentControlController::class, 'show'])->name('show');
    });
    Route::get('/policy', [PolicyDocumentController::class, 'index'])->middleware("can:access-module,'policy'")->name('policy.index');
    Route::get('/policy/{document}/file', [PolicyDocumentController::class, 'file'])->middleware("can:access-module,'policy'")->name('policy.file');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
    Route::get('/workspace-backup', [WorkspaceController::class, 'backup']);
    Route::post('/workspace/{source}/chunks', [WorkspaceController::class, 'chunks']);
    Route::get('/workspace', [WorkspaceController::class, 'snapshot']);
    Route::post('/workspace/{source}', [WorkspaceController::class, 'store']);
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/modules/{module}', [ModuleController::class, 'show'])->middleware('can:access-module,module')->name('modules.show');
    Route::resource('users', UserController::class)->except(['show', 'destroy'])->middleware('can:manage-users');
});

require __DIR__.'/csms.php';
