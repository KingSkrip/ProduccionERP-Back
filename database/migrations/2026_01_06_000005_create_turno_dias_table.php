<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turno_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos')->onDelete('cascade');

            // Día de la semana (0=Domingo, 1=Lunes, ..., 6=Sábado)
            $table->tinyInteger('dia_semana'); // 0-6

            // Configuración específica del día
            $table->boolean('es_laborable')->default(true);
            $table->boolean('es_descanso')->default(false);

            // Horarios específicos (sobrescriben los del turno si no son NULL)
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->time('hora_inicio_comida')->nullable();
            $table->time('hora_fin_comida')->nullable();

            // Banderas especiales
            $table->boolean('entra_dia_anterior')->default(false);
            $table->boolean('sale_dia_siguiente')->default(false);

            $table->timestamps();

            // Un solo registro por turno y día
            $table->unique(['turno_id', 'dia_semana'], 'uq_turno_dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turno_dias');
    }
};