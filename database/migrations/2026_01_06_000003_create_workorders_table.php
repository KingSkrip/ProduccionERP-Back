<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workorders', function (Blueprint $table) {
            $table->id();

            // Usuario que solicita la tarea
            $table->foreignId('solicitante_id')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->onDelete('cascade');

            // Jefe que aprueba/rechaza
            $table->foreignId('aprobador_id')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->onDelete('set null');

            // Estatus actual
            $table->foreignId('status_id')
                ->nullable()
                ->constrained('statuses')
                ->onDelete('cascade');

            // Datos de la tarea
            $table->string('titulo', 200)->nullable();
            $table->text('descripcion')->nullable();

            // Fechas
            $table->dateTime('fecha_solicitud')->nullable()->useCurrent();
            $table->dateTime('fecha_aprobacion')->nullable();
            $table->dateTime('fecha_cierre')->nullable();

            // Comentarios
            $table->text('comentarios_aprobador')->nullable();
            $table->text('comentarios_solicitante')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workorders');
    }
};