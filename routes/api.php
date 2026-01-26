<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalogos\CatalogosController;
use App\Http\Controllers\Colaboradores\SoliVacacionesController;
use App\Http\Controllers\Personalizacion\Dashboard\DataDashboardController;
use App\Http\Controllers\Personalizacion\Perfil\PerfilController;
use App\Http\Controllers\RH\Nominas\EmpresaUno\EmpresaUnoController;
use App\Http\Controllers\SuperAdmin\AutorizacionPedidos\AutorizacionPedidosController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador\ColaboradorController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\RH\RHController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\SuAdmin\SuAdminController;
use App\Http\Controllers\SuperAdmin\ReportesProduccion\ReportesProduccionController;
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
    Route::get('{id}/edit', [ColaboradorController::class, 'edit'])->name('superadmin.colaborador.show');
    Route::post('{id}/update', [ColaboradorController::class, 'update'])->name('superadmin.colaborador.update');
    Route::delete('{id}', [ColaboradorController::class, 'destroy'])->name('superadmin.colaborador.destroy');



    Route::put('usuarios/{id}/status', [ColaboradorController::class, 'updateStatus']);
});

//GESTIONAR ROLES
Route::prefix('roles')->middleware('jwt.auth')->group(function () {
    Route::get('data', [RolesController::class, 'index'])->name('superadmin.roles.index');
    Route::post('createrol', [RolesController::class, 'store'])->name('superadmin.roles.store');
    Route::get('rol/{id}', [RolesController::class, 'edit'])->name('superadmin.roles.show');
    Route::put('rol/{id}', [RolesController::class, 'update'])->name('superadmin.roles.update');
    Route::delete('rol/{id}', [RolesController::class, 'destroy'])->name('superadmin.roles.destroy');
});


//CATALOGOS
Route::prefix('catalogos')->middleware('jwt.auth')->group(function () {
    Route::get('getAll', [CatalogosController::class, 'getAllCatalogos']);
    Route::get('getdepartamentos', [CatalogosController::class, 'getDepartamentos']);
    Route::get('getroles', [CatalogosController::class, 'getRoles']);
    Route::get('getsubroles', [CatalogosController::class, 'getSubroles']);
    Route::get('getstatuses', [CatalogosController::class, 'getStatuses']);
});



Route::prefix('rh/E_ONE')->middleware('jwt.auth')->group(function () {
    Route::get('empresa1/empleados', [EmpresaUnoController::class, 'index'])->name('EONE.index');
});



Route::prefix('colaboradores')->middleware('jwt.auth')->group(function () {
    Route::get('vacaciones', [SoliVacacionesController::class, 'index']);
    Route::get('vacaciones/create', [SoliVacacionesController::class, 'create']);
    Route::post('vacaciones/store', [SoliVacacionesController::class, 'store']);
    Route::get('vacaciones/{id}/show', [SoliVacacionesController::class, 'show']);
    Route::get('vacaciones/{id}/edit', [SoliVacacionesController::class, 'edit']);
    Route::put('vacaciones/{id}/update', [SoliVacacionesController::class, 'update']);
    Route::delete('vacaciones/{id}/delete', [SoliVacacionesController::class, 'destroy']);
});



Route::middleware(['jwt.auth'])  // Keep only your JWT auth
    ->prefix('firebird')     // Combine the prefixes manually
    ->group(function () {
        Route::get('pedidos', [AutorizacionPedidosController::class, 'index']);
        Route::put('pedidos/{id}/autorizar-credito', [AutorizacionPedidosController::class, 'update']);
    });



Route::prefix('reportes-produccion')->group(function () {

    // GET: Obtener reportes con filtros de fecha
    Route::get('/', [ReportesProduccionController::class, 'index']);

    // GET: Obtener resumen estadístico (opcional)
    Route::get('/summary', [ReportesProduccionController::class, 'getSummary']);


    Route::get('/estampados', [ReportesProduccionController::class, 'getEstampado']);
    Route::get('/tintoreria', [ReportesProduccionController::class, 'getTintoreria']);

    Route::get('/tejido', [ReportesProduccionController::class, 'getProduccionTejido']);
    Route::get('/revisado', [ReportesProduccionController::class, 'getRevisadoTejido']);
    Route::get('/pendientes', [ReportesProduccionController::class, 'getPorRevisarTejido']); // porrevisar → pendientes
    Route::get('/con-saldo', [ReportesProduccionController::class, 'getSaldosTejido']);
    Route::get('/entregado-embarques', [ReportesProduccionController::class, 'getEntregadoaEmbarques']);


    Route::get('/facturado', [ReportesProduccionController::class, 'getFacturado']);
    Route::get('/tejido-resumen', [ReportesProduccionController::class, 'getTejido']);


    Route::get('/acabado', [ReportesProduccionController::class, 'getAcabadoReal']);

    // GET: Obtener reportes por departamento específico (opcional)
    Route::get('/departamento/{id}', [ReportesProduccionController::class, 'getByDepartment']);
});