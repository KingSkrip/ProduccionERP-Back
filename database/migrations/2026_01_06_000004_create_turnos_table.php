<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->char('firebird_empresa', 2);
            $table->string('clave', 10)->nullable();
            $table->string('nombre', 50);
            
            // Campos generales (pueden ser NULL si se configuran por día)
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->time('hora_inicio_comida')->nullable();
            $table->time('hora_fin_comida')->nullable();
            
            // Banderas especiales
            $table->boolean('entra_dia_anterior')->default(false);
            $table->boolean('sale_dia_siguiente')->default(false);
            
            // ❌ ELIMINADO: multiplicador_falta (se calcula dinámicamente)
            
            $table->foreignId('status_id')->constrained('statuses')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['firebird_empresa', 'nombre'], 'uq_turno_empresa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};