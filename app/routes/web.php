<?php

use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RegistrationController;
use App\Http\Middleware\RegistrationOpen;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::inertia('/privacidad', 'Privacy')->name('privacy');
Route::get('/r/{code}', [ReferralController::class, 'show'])->middleware('throttle:referral')->name('referral');
Route::post('/preferencias', [ReferralController::class, 'preferences'])->middleware('throttle:30,1')->name('preferences');
Route::get('/bienvenida', [RegistrationController::class, 'welcome'])->name('welcome');

Route::middleware('guest')->group(function (): void {
    Route::post('/registro', [RegistrationController::class, 'store'])->middleware([RegistrationOpen::class, 'throttle:registration'])->name('register');
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:login');
    Route::get('/recuperar-password', [AuthController::class, 'forgot'])->name('password.request');
    Route::post('/recuperar-password', [AuthController::class, 'email'])->middleware('throttle:account-mail')->name('password.email');
    Route::get('/activar/{token}', [AuthController::class, 'activation'])->name('activation.show');
    Route::post('/activar', [AuthController::class, 'activate'])->middleware('throttle:login')->name('activation.store');
    Route::get('/restablecer-password/{token}', [AuthController::class, 'reset'])->name('password.reset');
    Route::post('/restablecer-password', [AuthController::class, 'updatePassword'])->middleware('throttle:login')->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/verificar-email', [AuthController::class, 'forgot'])->name('verification.notice');
    Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('verified')->name('dashboard');
    Route::patch('/perfil', [DashboardController::class, 'update'])->middleware('verified')->name('profile.update');
});
