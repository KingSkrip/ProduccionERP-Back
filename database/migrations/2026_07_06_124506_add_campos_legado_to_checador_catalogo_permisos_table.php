<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checador_catalogo_permisos', function (Blueprint $table) {
            // Inspirado en Incidencias.Tipo_Unidad (viejo Geminis): 'D' = días, 'H' = horas
            $table->enum('tipo_unidad', ['dias', 'horas'])->default('horas')->after('duracion_default_minutos');

            // Inspirado en Incidencias.Suma_a_hrs
            $table->boolean('suma_a_horas_trabajadas')->default(false)->after('tipo_unidad');

            $table->boolean('requiere_regreso_mismo_dia')->default(true)->after('tipo_unidad');
            // Referencia al código viejo de Geminis para trazabilidad en migración de datos históricos
            $table->string('codigo_legado', 10)->nullable()->after('clave');
        });
    }

    public function down(): void
    {
        Schema::table('checador_catalogo_permisos', function (Blueprint $table) {
            $table->dropColumn(['tipo_unidad', 'suma_a_horas_trabajadas', 'codigo_legado']);
        });
    }
};