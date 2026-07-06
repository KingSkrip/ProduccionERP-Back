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
                ->onDelete('cascade');
            $table->char('firebird_empresa', 2)->nullable();
            $table->enum('tipo', ['normal', 'extraordinario'])->default('normal');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->string('motivo');
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->foreignId('aprobado_por')->nullable()
                ->constrained('users_firebird_identities')->nullOnDelete();
            $table->dateTime('fecha_resolucion')->nullable();
            $table->text('comentarios_aprobador')->nullable();
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
