<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ATMController;

// Authentication Routes
Route::get('/', function () {
    return view('auth.login');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/fingerprint', [AuthController::class, 'loginWithFingerprint']);

// WebAuthn Routes
Route::get('/webauthn/challenge', [AuthController::class, 'generateChallenge']);
Route::post('/webauthn/verify', [AuthController::class, 'verifyCredential']);

// WebAuthn Registration (protected route)
Route::middleware(['auth'])->group(function () {
    Route::get('/webauthn/register/options', [AuthController::class, 'getRegistrationOptions']);
    Route::post('/webauthn/register', [AuthController::class, 'registerCredential']);
});

// Protected Routes
Route::middleware(['auth'])->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // ATM Routes
    Route::get('/atm', [ATMController::class, 'index'])->name('atm.index');
    
    // Withdrawal
    Route::get('/atm/withdraw', [ATMController::class, 'showWithdraw'])->name('atm.withdraw');
    Route::post('/atm/withdraw', [ATMController::class, 'processWithdraw']);
    
    // Transfer
    Route::get('/atm/transfer', [ATMController::class, 'showTransfer'])->name('atm.transfer');
    Route::post('/atm/transfer', [ATMController::class, 'processTransfer']);
    
    // History (NEW)
    Route::get('/atm/history', [ATMController::class, 'showHistory'])->name('atm.history');
    
    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});