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
            $table->text('descripcion')->nullable();
            $table->integer('duracion_default_minutos')->nullable(); // Ej: 60 para comida
            $table->boolean('requiere_aprobacion')->default(true);
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