<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Personalizacion\Dashboard\DataDashboardController;
use App\Http\Controllers\Personalizacion\Perfil\PerfilController;
use App\Http\Controllers\RH\Nominas\EmpresaUno\EmpresaUnoController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador\ColaboradorController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\RH\RHController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\SuAdmin\SuAdminController;
use App\Http\Controllers\SuperAdmin\Roles\RolesController;
use Illuminate\Support\Facades\Route;

Route::options('/{any}', function () {
    return response()->json(['status' => 'ok'], 200);
})->where('any', '.*');

Route::prefix('auth')->group(function () {
    //INICIAR SESION
    Route::post('sign-in', [AuthController::class, 'signIn']);
    Route::post('sign-in-with-token', [AuthController::class, 'signInWithToken']);
    //REGISTRARSE
    Route::post('sign-up', [AuthController::class, 'signUp']);
    //CERRAR SESION
    Route::post('sign-out', [AuthController::class, 'signOut']);
    //OLVIDE CONTRASEÑA
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    //REINIICAR CONTRASEÑA
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    //DESBLOQUEAR SESION
    Route::post('unlock-session', [AuthController::class, 'unlockSession']);
});

//DASHBOARD PERSONAL
Route::prefix('dash')->group(function () {
    Route::get('me', [DataDashboardController::class, 'me']);
    Route::post('update-status', [DataDashboardController::class, 'updateStatus']);
});


//EDITAR PERFIL PERSONAL
Route::middleware('jwt.auth')->group(function () {
    Route::get('perfil', [PerfilController::class, 'show'])->name('hola');
    Route::post('perfil', [PerfilController::class, 'updatePerfil']);
    Route::put('perfil/password', [PerfilController::class, 'updatePassword']);
    Route::delete('perfil', [PerfilController::class, 'destroy']);
});


//GESTIONAR SUADMINS
Route::prefix('superadmin')->middleware('jwt.auth')->group(function () {
    Route::get('data', [SuAdminController::class, 'index'])->name('superadmin.suadmin.index');
    Route::post('suadmin', [SuAdminController::class, 'store'])->name('superadmin.suadmin.store');
    Route::get('suadmin/{id}', [SuAdminController::class, 'edit'])->name('superadmin.suadmin.show');
    Route::put('suadmin/{id}', [SuAdminController::class, 'update'])->name('superadmin.suadmin.update');
    Route::delete('suadmin/{id}', [SuAdminController::class, 'destroy'])->name('superadmin.suadmin.destroy');
});

//GESTIONAR RH
Route::prefix('rh')->middleware('jwt.auth')->group(function () {
    Route::get('data', [RHController::class, 'index'])->name('superadmin.rh.index');
    Route::post('suadmin', [RHController::class, 'store'])->name('superadmin.rh.store');
    Route::get('suadmin/{id}', [RHController::class, 'edit'])->name('superadmin.rh.show');
    Route::put('suadmin/{id}', [RHController::class, 'update'])->name('superadmin.rh.update');
    Route::delete('suadmin/{id}', [RHController::class, 'destroy'])->name('superadmin.rh.destroy');
});

//GESTIONAR COLABORADOR
Route::prefix('colaborador')->middleware('jwt.auth')->group(function () {
    Route::get('data', [ColaboradorController::class, 'index'])->name('superadmin.colaborador.index');
    Route::post('suadmin', [ColaboradorController::class, 'store'])->name('superadmin.colaborador.store');
    Route::get('suadmin/{id}', [ColaboradorController::class, 'edit'])->name('superadmin.colaborador.show');
    Route::put('suadmin/{id}', [ColaboradorController::class, 'update'])->name('superadmin.colaborador.update');
    Route::delete('suadmin/{id}', [ColaboradorController::class, 'destroy'])->name('superadmin.colaborador.destroy');
});

//GESTIONAR ROLES
Route::prefix('roles')->middleware('jwt.auth')->group(function () {
    Route::get('data', [RolesController::class, 'index'])->name('superadmin.roles.index');
    Route::post('createrol', [RolesController::class, 'store'])->name('superadmin.roles.store');
    Route::get('rol/{id}', [RolesController::class, 'edit'])->name('superadmin.roles.show');
    Route::put('rol/{id}', [RolesController::class, 'update'])->name('superadmin.roles.update');
    Route::delete('rol/{id}', [RolesController::class, 'destroy'])->name('superadmin.roles.destroy');
});





Route::prefix('rh/E_ONE')->middleware('jwt.auth')->group(function () {
    Route::get('empresa1/empleados', [EmpresaUnoController::class, 'index'])->name('EONE.index');
});
