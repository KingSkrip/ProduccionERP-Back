<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_permisos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_firebird_identity_id')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->cascadeOnDelete();

            $table->foreignId('checador_catalogo_permiso_id')
                ->nullable()
                ->constrained('checador_catalogo_permisos')
                ->nullOnDelete();

            $table->char('firebird_empresa', 2)->nullable();

            $table->enum('tipo', ['normal', 'extraordinario'])
                ->nullable()
                ->default('normal');

            $table->date('fecha_inicio')
                ->nullable();
            $table->date('fecha_fin')
                ->nullable();

            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();

            $table->boolean('no_regresa')
                ->nullable()
                ->default(false);

   $table->boolean('todo_el_dia')
    ->nullable()
    ->default(false);

            $table->enum('tipo_pago_tiempo', [
                'tiempo_por_tiempo',
                'dia_descanso',
                'sin_goce',
            ])->nullable();

            $table->integer('minutos_ausencia')->nullable();

            $table->date('fecha_reposicion')->nullable();

            $table->time('hora_inicio_reposicion')->nullable();

            $table->time('hora_fin_reposicion')->nullable();

            $table->string('justificacion_pago_tiempo')->nullable();
            $table->string('motivo')->nullable();

            $table->foreignId('permiso_origen_id')
                ->nullable()
                ->constrained('checador_permisos')
                ->nullOnDelete();

            // Indica si el tiempo de ausencia ya fue pagado
            $table->boolean('tiempo_pagado')
                ->default(false);

            // Cuándo se pagó
            $table->timestamp('fecha_tiempo_pagado')
                ->nullable();

            // Registro de checador donde se pagó el tiempo
            $table->unsignedBigInteger('pagado_en_registro_id')
                ->nullable();

            // Estado GENERAL, calculado a partir de estado_rh + estado_jefe.
            // No se edita directo, lo recalcula el Service en cada resolver().
            $table->enum('estado', [
                'pendiente',
                'aprobado',
                'rechazado',
                'solicitado',
            ])->default('solicitado')
                ->nullable();

            // ===== Carril RH =====
            $table->enum('estado_rh', [
                'pendiente',
                'aprobado',
                'rechazado',
                'no_aplica',
            ])->default('pendiente')
                ->nullable();

            $table->foreignId('aprobado_por_rh')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->nullOnDelete();

            $table->dateTime('fecha_resolucion_rh')->nullable();
            $table->text('comentarios_rh')->nullable();

            // ===== Carril Jefe =====
            $table->enum('estado_jefe', [
                'pendiente',
                'aprobado',
                'rechazado',
                'no_aplica',
            ])->default('pendiente')
                ->nullable();

            $table->foreignId('aprobado_por_jefe')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->nullOnDelete();

            $table->dateTime('fecha_resolucion_jefe')->nullable();
            $table->text('comentarios_jefe')->nullable();
            $table->timestamps();

            $table->index(
                ['user_firebird_identity_id', 'fecha_inicio', 'fecha_fin'],
                'idx_perm_usr_fecha'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_permisos');
    }
};