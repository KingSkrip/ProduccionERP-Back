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
                ->constrained('users_firebird_identities')
                ->cascadeOnDelete();

            $table->foreignId('checador_catalogo_permiso_id')
                ->nullable()
                ->constrained('checador_catalogo_permisos')
                ->nullOnDelete();

            $table->char('firebird_empresa', 2)->nullable();

            $table->enum('tipo', ['normal', 'extraordinario'])
                ->default('normal');

            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();

            $table->string('motivo');

            // Estado GENERAL, calculado a partir de estado_rh + estado_jefe.
            // No se edita directo, lo recalcula el Service en cada resolver().
            $table->enum('estado', [
                'pendiente',
                'aprobado',
                'rechazado',
                'solicitado',
            ])->default('solicitado');

            // ===== Carril RH =====
            $table->enum('estado_rh', [
                'pendiente',
                'aprobado',
                'rechazado',
                'no_aplica',
            ])->default('pendiente');

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
            ])->default('pendiente');

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