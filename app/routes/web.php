<?php

use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\MarketingEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RegistrationController;
use App\Http\Middleware\RegistrationOpen;
use App\Http\Middleware\MarketingAdmin;
use App\Http\Controllers\LandingAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('/l/{slug}', [WelcomeController::class, 'legacy'])->name('landing.legacy');
Route::prefix('admin/landings')->middleware(['auth', 'verified', MarketingAdmin::class])->group(function (): void {
    Route::get('/', [LandingAdminController::class, 'index'])->name('landings.index');
    Route::get('/crear', [LandingAdminController::class, 'create'])->name('landings.create');
    Route::post('/', [LandingAdminController::class, 'store'])->name('landings.store');
    Route::post('/import', [LandingAdminController::class, 'import'])->name('landings.import-local');
    Route::get('/{landing}/editar', [LandingAdminController::class, 'edit'])->name('landings.edit');
    Route::put('/{landing}', [LandingAdminController::class, 'update'])->name('landings.update');
    Route::get('/{landing}/preview', [WelcomeController::class, 'preview'])->name('landings.preview');
    Route::get('/{landing}/preview/confirmado', [RegistrationController::class, 'preview'])->name('landings.confirmation-preview');
    Route::get('/{landing}/export', [LandingAdminController::class, 'export'])->name('landings.export');
    Route::post('/{landing}/import', [LandingAdminController::class, 'import'])->name('landings.import');
    Route::post('/{landing}/duplicar', [LandingAdminController::class, 'duplicate'])->name('landings.duplicate');
    Route::post('/{landing}/publicar', [LandingAdminController::class, 'publish'])->name('landings.publish');
    Route::post('/{landing}/archivar', [LandingAdminController::class, 'archive'])->name('landings.archive');
});
Route::post('/medicion', MarketingEventController::class)->middleware('throttle:30,1')->name('marketing.event');
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

// A server receipt bound to this published landing is required to display thanks.
Route::get('/{slug}/gracias', [RegistrationController::class, 'confirmed'])
    ->where('slug', \App\Support\LandingSlug::pattern())->name('registration.thanks');
// Keep previous session receipts and shared confirmation URLs valid.
Route::get('/{slug}/confirmado', [RegistrationController::class, 'confirmed'])
    ->where('slug', \App\Support\LandingSlug::pattern())->name('registration.confirmed');

// Last, single-segment GET only. Reserved POST-only paths retain their 405 response.
Route::get('/{slug}', [WelcomeController::class, 'show'])
    ->where('slug', \App\Support\LandingSlug::pattern())->name('landing.show');
