<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_permisos_extraordinarios', function (Blueprint $table) {
            $table->id();

            // Ligado 1 a 1 con el usuario (igual que user_puestos / user_turnos)
            $table->unsignedBigInteger('user_firebird_identity_id');

            // ¿Puede salir en cualquier momento?
            $table->boolean('puede_salir_cualquier_momento')->default(false);
            $table->boolean('salir_cualquier_momento_necesita_permiso')->default(false);

            // ¿Puede salir a comer?
            $table->boolean('puede_salir_comer')->default(false);
            $table->boolean('salir_comer_necesita_permiso')->default(false);

            // ¿Puede entrar tarde?
            $table->boolean('puede_entrar_tarde')->default(false);

            // Tolerancia: puede ser un número de minutos o "ilimitada" (el 99+ que viste en la tabla)
            $table->boolean('tolerancia_ilimitada')->default(false);
            $table->unsignedInteger('tolerancia_minutos')->nullable();

            // Hora límite de entrada (06:00, 08:40, etc.)
            $table->time('hora_limite')->nullable();

            // Campo libre para permisos raros que no entran en las columnas de arriba
            // (ej: "Descansa 1 día al mes sin pago ni repercusiones")
            $table->text('permiso_extraordinario_otro')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique('user_firebird_identity_id', 'uq_permisos_extra_user');

            $table->foreign('user_firebird_identity_id', 'fk_permisos_extra_user')
                ->references('id')->on('users_firebird_identities')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_permisos_extraordinarios');
    }
};