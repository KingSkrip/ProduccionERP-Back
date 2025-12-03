<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DataDashboardController;
use App\Http\Controllers\PerfilController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UsuarioController;

Route::options('/{any}', function () {
    return response()->json(['status' => 'ok'], 200);
})->where('any', '.*');

Route::prefix('auth')->group(function () {

    // ----- AUTENTICACIÓN BÁSICA -----
    Route::post('sign-in', [AuthController::class, 'signIn']);
    Route::post('sign-in-with-token', [AuthController::class, 'signInWithToken']);
    Route::post('sign-up', [AuthController::class, 'signUp']);
    Route::post('sign-out', [AuthController::class, 'signOut']);

    // ----- RECUPERACIÓN DE CONTRASEÑA -----
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('unlock-session', [AuthController::class, 'unlockSession']);
});


Route::prefix('dash')->group(function () {
    // ----- RUTAS NUEVAS PARA ANGULAR -----
    Route::get('me', [DataDashboardController::class, 'me']);
    Route::post('update-status', [DataDashboardController::class, 'updateStatus']);
});



Route::middleware('jwt.auth')->group(function () {

    Route::get('perfil',          [PerfilController::class, 'show'])->name('hola');
    Route::post('perfil',          [PerfilController::class, 'updatePerfil']);
    Route::put('perfil/password', [PerfilController::class, 'updatePassword']);
    Route::delete('perfil',       [PerfilController::class, 'destroy']);
});
