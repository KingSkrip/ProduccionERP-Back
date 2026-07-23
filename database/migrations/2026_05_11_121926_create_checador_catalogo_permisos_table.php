<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_catalogo_permisos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // Ej: "Hora de comida", "Cita médica", "Trámite personal"
            $table->string('clave', 30)->unique(); // Ej: "COMIDA", "MEDICO", "TRAMITE"
            $table->string('codigo_legado', 10)->nullable(); // Referencia al código viejo de Geminis para trazabilidad en migración de datos históricos
            $table->text('descripcion')->nullable();
            $table->integer('duracion_default_minutos')->nullable(); // Ej: 60 para comida

            // Inspirado en Incidencias.Tipo_Unidad (viejo Geminis): 'D' = días, 'H' = horas
            $table->enum('tipo_unidad', ['dias', 'horas'])->default('horas');

            // Inspirado en Incidencias.Suma_a_hrs
            $table->boolean('suma_a_horas_trabajadas')->default(false);

            $table->boolean('requiere_aprobacion')->default(true);
            $table->boolean('requiere_regreso_mismo_dia')->default(true);

            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(0); // para ordenar en el combo/dropdown
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_catalogo_permisos');
    }
};