<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SuperAdmin\AutorizacionPedidos\AutorizacionPedidosController;

Route::middleware(['api', 'jwt.auth'])
    ->prefix('firebird')
    ->group(function () {
        // GET para listar pedidos
        Route::get('pedidos', [AutorizacionPedidosController::class, 'index']);
        // PUT para autorizar
        Route::put('pedidos/{id}/autorizar-credito', [AutorizacionPedidosController::class, 'update']);
    });