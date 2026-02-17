<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalogos\CatalogosController;
use App\Http\Controllers\Colaboradores\SoliVacacionesController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\Personalizacion\Dashboard\DataDashboardController;
use App\Http\Controllers\Personalizacion\Perfil\PerfilController;
use App\Http\Controllers\RH\Nominas\EmpresaUno\EmpresaUnoController;
use App\Http\Controllers\SuperAdmin\AutorizacionPedidos\AutorizacionPedidosController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\AllUsersController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador\ColaboradorController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\RH\RHController;
use App\Http\Controllers\SuperAdmin\GestionarUsuarios\SuAdmin\SuAdminController;
use App\Http\Controllers\SuperAdmin\ReportesProduccion\ReportesProduccionController;
use App\Http\Controllers\SuperAdmin\Roles\RolesController;
use App\Http\Controllers\Clientes\EstadosCuentaController;
use App\Http\Controllers\TaskController;
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
    Route::get('/', [ReportesProduccionController::class, 'index']);
    Route::get('/summary', [ReportesProduccionController::class, 'getSummary']);
    Route::get('/estampados', [ReportesProduccionController::class, 'getEstampado']);
    Route::get('/tintoreria', [ReportesProduccionController::class, 'getTintoreria']);
    Route::get('/tejido', [ReportesProduccionController::class, 'getProduccionTejido']);
    Route::get('/revisado', [ReportesProduccionController::class, 'getRevisadoTejido']);
    Route::get('/pendientes', [ReportesProduccionController::class, 'getPorRevisarTejido']);
    Route::get('/con-saldo', [ReportesProduccionController::class, 'getSaldosTejido']);
    Route::get('/entregado-embarques', [ReportesProduccionController::class, 'getEntregadoaEmbarques']);
    Route::get('/facturado', [ReportesProduccionController::class, 'getFacturado']);
    Route::get('/tejido-resumen', [ReportesProduccionController::class, 'getTejido']);
    Route::get('/acabado', [ReportesProduccionController::class, 'getAcabadoReal']);
    Route::get('/departamento/{id}', [ReportesProduccionController::class, 'getByDepartment']);
    Route::get('all', [ReportesProduccionController::class, 'getAllReports']);
});


Route::prefix('tasks')->middleware('jwt.auth')->group(function () {
    Route::get('/', [TaskController::class, 'index']);              // listar
    Route::post('/store', [TaskController::class, 'store']);        // crear
    Route::get('/{id}/show', [TaskController::class, 'show']);      // ver 1
    Route::put('/{id}/update', [TaskController::class, 'update']);  // actualizar
    Route::delete('/{id}/delete', [TaskController::class, 'destroy']); // borrar
});



Route::middleware('jwt.auth')->group(function () {
    Route::get('users/all', [AllUsersController::class, 'index']);

    // ============================================
    // LISTADOS (GET) - Estos estaban faltando
    // ============================================

    Route::get('/mailbox/general',      [MailboxController::class, 'general']);
    Route::get('/mailbox/enviados',     [MailboxController::class, 'sent']);
    Route::get('/mailbox/borradores',   [MailboxController::class, 'drafts']);
    Route::get('/mailbox/eliminados',   [MailboxController::class, 'trash']);
    Route::get('/mailbox/spam',         [MailboxController::class, 'spam']);

    // 🔥 ESTAS SON LAS QUE FALTABAN - FILTROS PERSONALIZADOS
    Route::get('/mailbox/important',    [MailboxController::class, 'important']);
    Route::get('/mailbox/starred',      [MailboxController::class, 'starred']);

    // ============================================
    // CREAR/GUARDAR
    // ============================================

    Route::post('mailbox/drafts/store', [MailboxController::class, 'storeDraft']);

    // ============================================
    // ACCIONES SOBRE MAILBOX_ITEMS (PATCH)
    // ============================================

    // Por ID de MailboxItem
    Route::patch('/mailbox/{id}/read',      [MailboxController::class, 'markRead']);
    Route::patch('/mailbox/{id}/star',      [MailboxController::class, 'toggleStar']);
    Route::patch('/mailbox/{id}/important', [MailboxController::class, 'toggleImportant']);
    Route::patch('/mailbox/{id}/move',      [MailboxController::class, 'move']);

    // Por ID de Workorder (cuando no existe MailboxItem)
    Route::patch('mailbox/workorder/{workorderId}/read',      [MailboxController::class, 'markReadByWorkorder']);
    Route::patch('mailbox/workorder/{workorderId}/star',      [MailboxController::class, 'toggleStarByWorkorder']);
    Route::patch('mailbox/workorder/{workorderId}/important', [MailboxController::class, 'toggleImportantByWorkorder']);
    Route::patch('mailbox/workorder/{workorderId}/move',      [MailboxController::class, 'moveByWorkorder']);

    Route::get('mailbox/workorder/{id}', [MailboxController::class, 'showWorkorder']);


    Route::post('/mailbox/reply', [MailboxController::class, 'replyes']);
});








Route::prefix('estados-cuenta')->middleware('jwt.auth')->group(function () {
    Route::get('/', [EstadosCuentaController::class, 'index']);
    Route::get('/resumen', [EstadosCuentaController::class, 'resumen']);
    Route::get('/anio/{anio}', [EstadosCuentaController::class, 'porAnio']);
    Route::get('/{id}', [EstadosCuentaController::class, 'show']);
    Route::get('/{id}/pdf', [EstadosCuentaController::class, 'descargarPDF']);
    Route::post('/descargar-multiples', [EstadosCuentaController::class, 'descargarMultiples']);
    Route::post('/{id}/enviar-email', [EstadosCuentaController::class, 'enviarEmail']);
    Route::post('/generar', [EstadosCuentaController::class, 'generar']);
    Route::patch('/{id}/estado', [EstadosCuentaController::class, 'actualizarEstado']);
    Route::delete('/{id}', [EstadosCuentaController::class, 'destroy']);
});

/**
 * SIEMPRE QUE SE AGREGE UNA NUEVA RUTA HAY QUE AGREGARLA A  
 * app/Http/Middleware/EncryptJsonResponse.php
 * PARA ENCRIPTAR LA PETICION
 */