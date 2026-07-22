<?php

use App\Http\Controllers\Agenda\AgendaController;
use App\Http\Controllers\Scanner\ScannerEmbarquesController;
use App\Http\Controllers\Agenda\AgendarJuntasController;
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
use App\Http\Controllers\Clientes\PedidosController;
use Illuminate\Http\Request;
use App\Http\Controllers\Agentes\EstadosCuentaAgentesController;
use App\Http\Controllers\Agentes\PedidosAgentesController;
use App\Http\Controllers\Area\AreaController;
use App\Http\Controllers\Checador\ChecadorAsistenciaController;
use App\Http\Controllers\Checador\ChecadorController;
use App\Http\Controllers\Checador\ChecadorPermisoController;
use App\Http\Controllers\Checador\ChecadorQrController;
use App\Http\Controllers\Checador\ChecadorIdentidadController;
use App\Http\Controllers\Puestos\PuestoController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\Turnos\TurnoController;
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



Route::middleware(['jwt.auth'])->prefix('firebird')->group(function () {
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
    Route::get('facturado-por-dia', [ReportesProduccionController::class, 'getFacturadoPorDia']);
    Route::get('/estampados-por-dia', [ReportesProduccionController::class, 'getEstampadoPorDia']);
    Route::get('/tintoreria-por-dia', [ReportesProduccionController::class, 'getTintoreriaPorDia']);
    Route::get('/tejido-por-dia', [ReportesProduccionController::class, 'getTejidoPorDia']);
    Route::get('/acabado-por-dia', [ReportesProduccionController::class, 'getAcabadoPorDia']);
    Route::middleware('jwt.auth')->group(function () {
        Route::get('/ocultar/{z200_id}', [ReportesProduccionController::class, 'getEstadoOculto']);
        Route::post('/ocultar/{z200_id}', [ReportesProduccionController::class, 'toggleOcultar']);
    });
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
    Route::get('users/all-juntas', [AllUsersController::class, 'indexJuntas']);

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

    Route::patch('mailbox/workorder/{workorderId}/iniciar-ticket', [MailboxController::class, 'iniciarTicket']);
    Route::patch('mailbox/workorder/{workorderId}/finalizar-ticket', [MailboxController::class, 'finalizarTicket']);

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



    Route::get('perfil', [PerfilController::class, 'show'])->name('hola');
    Route::post('perfil', [PerfilController::class, 'updatePerfil']);
    Route::put('perfil/password', [PerfilController::class, 'updatePassword']);
    Route::delete('perfil', [PerfilController::class, 'destroy']);
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




Route::prefix('clientes/pedidos')->middleware('jwt.auth')->group(function () {
    Route::get('/', [PedidosController::class, 'index']);
    Route::get('/resumen', [PedidosController::class, 'resumen']);
    Route::get('/anio/{anio}', [PedidosController::class, 'porAnio'])->whereNumber('anio');
    Route::post('/descargar-multiples', [PedidosController::class, 'descargarMultiples']);
    Route::get('/{cvePed}', [PedidosController::class, 'show']);
    Route::get('/{cvePed}/pdf', [PedidosController::class, 'descargarPDF']);
    Route::post('/{cvePed}/email', [PedidosController::class, 'enviarEmail']);
    Route::delete('/{cvePed}', [PedidosController::class, 'destroy']);
});

Route::prefix('estados-Cu3nt4Ag3nT32')->middleware('jwt.auth')->group(function () {
    Route::get('/', [EstadosCuentaAgentesController::class, 'index']);
    Route::get('/resumen', [EstadosCuentaAgentesController::class, 'resumen']);
    Route::get('/anio/{anio}', [EstadosCuentaAgentesController::class, 'porAnio']);
    Route::get('/{id}', [EstadosCuentaAgentesController::class, 'show']);
    Route::get('/{id}/pdf', [EstadosCuentaAgentesController::class, 'descargarPDF']);
    Route::post('/descargar-multiples', [EstadosCuentaAgentesController::class, 'descargarMultiples']);
    Route::post('/{id}/enviar-email', [EstadosCuentaAgentesController::class, 'enviarEmail']);
    Route::post('/generar', [EstadosCuentaAgentesController::class, 'generar']);
    Route::patch('/{id}/estado', [EstadosCuentaAgentesController::class, 'actualizarEstado']);
    Route::delete('/{id}', [EstadosCuentaAgentesController::class, 'destroy']);
    Route::post('/generar-url', [EstadosCuentaAgentesController::class, 'generarUrlTemporal']);
});

Route::prefix('agentes/pedidos')->middleware('jwt.auth')->group(function () {
    Route::get('/', [PedidosAgentesController::class, 'index']);
    Route::get('/resumen', [PedidosAgentesController::class, 'resumen']);
    Route::get('/anio/{anio}', [PedidosAgentesController::class, 'porAnio'])->whereNumber('anio');
    Route::post('/descargar-multiples', [PedidosAgentesController::class, 'descargarMultiples']);
    Route::get('/{cvePed}', [PedidosAgentesController::class, 'show']);
    Route::get('/{cvePed}/pdf', [PedidosAgentesController::class, 'descargarPDF']);
    Route::post('/{cvePed}/email', [PedidosAgentesController::class, 'enviarEmail']);
    Route::delete('/{cvePed}', [PedidosAgentesController::class, 'destroy']);
    Route::get('/{cvePed}/detalle', [PedidosAgentesController::class, 'detalle']);
});

Route::apiResource('citas', AgendaController::class);
Route::get('usuarios-permitidos', [AgendaController::class, 'UsuariosPermitidosParaProvedores']);
Route::get('usuarios-permitidosAllUsers', [AgendaController::class, 'UsuariosPermitidosParaAllUsers']);
// Route::post('/proveedor', [AgendaController::class, 'storeProveedor']);


Route::prefix('citas')->group(function () {
    Route::post('/proveedor', [AgendaController::class, 'storeProveedor']);
    Route::get('index/proveedor',         [AgendaController::class, 'indexProveedor']);
    Route::patch('/{id}/estado', [AgendaController::class, 'updateEstado']);
    Route::put('/proveedor/update',  [AgendaController::class, 'updateProveedor']);
    Route::delete('/proveedor/destroy', [AgendaController::class, 'destroyProveedor']);
    Route::get('/admin/todas', [AgendaController::class, 'indexAdmin']);
});

// Juntas
Route::prefix('juntas')->group(function () {
    Route::get('/',                       [AgendaController::class, 'indexJunta']);
    Route::post('/',                      [AgendaController::class, 'storeJunta']);
    Route::patch('/{id}/estado',          [AgendaController::class, 'updateEstadoJunta']);
    Route::patch('/{id}/asistencia', [AgendaController::class, 'updateAsistencia']);
    Route::put('/{id}',                   [AgendaController::class, 'updateJunta']);
    Route::delete('/{id}',               [AgendaController::class, 'destroyJunta']);
});

Route::prefix('scanner')->middleware('jwt.auth')->group(function () {
    Route::get('/embarques',  [ScannerEmbarquesController::class, 'index']);
    Route::post('/embarques', [ScannerEmbarquesController::class, 'scan']);
    Route::post('/inventario', [ScannerEmbarquesController::class, 'verificarInventario']);
});

Route::prefix('checador')->middleware('jwt.auth')->group(function () {
    // QR
    Route::post('/qr/{identityId}/generar', [ChecadorQrController::class, 'generar']);
    Route::get('/qr/{identityId}', [ChecadorQrController::class, 'mostrar']);
    Route::post('/qr/{identityId}/revocar', [ChecadorQrController::class, 'revocar']);
    Route::post('/qr/registrar', [ChecadorQrController::class, 'registrar']);
    Route::get('/historial/{identityId}', [ChecadorQrController::class, 'historial']);

    // 🔥 NUEVO: registro manual + dashboard del día
    Route::get('/buscar-empleado', [ChecadorController::class, 'buscarEmpleado']);
    Route::post('/registrar-manual', [ChecadorController::class, 'registrarManual']);
    Route::get('/hoy', [ChecadorController::class, 'hoy']);


    // Permisos
    Route::get('/permisos/catalogo', [ChecadorPermisoController::class, 'catalogo']);
    Route::post('/permisos/solicitar', [ChecadorPermisoController::class, 'solicitar']);
    Route::get('/permisos/mis-permisos', [ChecadorPermisoController::class, 'misPermisos']);
    Route::get('/permisos/pendientes-jefe/{jefeId}', [ChecadorPermisoController::class, 'pendientesJefe']);
    Route::post('/permisos/{permisoId}/resolver/{rol}', [ChecadorPermisoController::class, 'resolver'])
        ->whereIn('rol', ['rh', 'jefe']);
    Route::get('/permisos/historial/{identityId}', [ChecadorPermisoController::class, 'historial']);
    Route::get('/permisos/historial-equipo/{jefeId}', [ChecadorPermisoController::class, 'historialEquipo']);

    // Permisos - RH
    Route::get('/permisos/pendientes-rh', [ChecadorPermisoController::class, 'pendientesRh']);
    Route::get('/permisos/historial-rh', [ChecadorPermisoController::class, 'historialRh']);

    // Asistencia / tarjeta - RH
    // 👇 Las literales van PRIMERO
    Route::get('/asistencia/equipo/semana', [ChecadorAsistenciaController::class, 'resumenEquipo']);
    Route::get('/asistencia/excel', [ChecadorAsistenciaController::class, 'excelEquipo']);

    // 👇 Las que tienen {identityId} van DESPUÉS
    Route::get('/asistencia/{identityId}/semana', [ChecadorAsistenciaController::class, 'resumenSemana'])
        ->whereNumber('identityId');
    Route::get('/asistencia/{identityId}/excel', [ChecadorAsistenciaController::class, 'excelSemana'])
        ->whereNumber('identityId');

    Route::patch('/identidades/{identityId}/ajuste-salida', [ChecadorIdentidadController::class, 'toggleAjusteSalida']);
    Route::patch('/identidades/{identityId}/credencial', [ChecadorIdentidadController::class, 'asignarCredencial']);
});


Route::get('mi-ip', function (Request $request) {
    $ip = $request->header('X-Real-IP')
        ?? $request->header('X-Forwarded-For')
        ?? $request->ip();
    return response()->json(['ip' => $ip]);
});

/*
|--------------------------------------------------------------------------
| Áreas
|--------------------------------------------------------------------------
*/
Route::prefix('areas')->middleware('jwt.auth')->group(function () {
    Route::get('/activas', [AreaController::class, 'activas']);
    Route::patch('/{id}/toggle-activo', [AreaController::class, 'toggleActivo']);

    Route::apiResource('', AreaController::class)
        ->parameters(['' => 'id']);
});

/*
|--------------------------------------------------------------------------
| Puestos
|--------------------------------------------------------------------------
*/
Route::prefix('puestos')->middleware('jwt.auth')->group(function () {
    Route::get('/activos', [PuestoController::class, 'activos']);
    Route::patch('/{id}/toggle-activo', [PuestoController::class, 'toggleActivo']);

    Route::apiResource('', PuestoController::class)
        ->parameters(['' => 'id']);
});

/*
|--------------------------------------------------------------------------
| Turnos
|--------------------------------------------------------------------------
*/
Route::prefix('turnos')->middleware('jwt.auth')->group(function () {

    // ← SIEMPRE antes del apiResource
    Route::get('/activos', [TurnoController::class, 'activos']);
    Route::patch('/{id}/dias/{diaSemana}', [TurnoController::class, 'actualizarDia']);

    Route::apiResource('', TurnoController::class)
        ->parameters(['' => 'id']);
});

/**
 * SIEMPRE QUE SE AGREGE UNA NUEVA RUTA HAY QUE AGREGARLA A  
 * app/Http/Middleware/EncryptJsonResponse.php
 * PARA ENCRIPTAR LA PETICION
 */