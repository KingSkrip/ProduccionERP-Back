<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->boolean('checador_ajuste_salida_puntual')
                ->default(false)
                ->after('firebird_prov_tabla')
                ->comment('Si es true: al checar salida unos minutos antes de su hora programada, se registra la hora programada en vez de la real (VENTANA_AJUSTE_SALIDA_MINUTOS en ChecadorScanService).');
        });
    }

    public function down(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->dropColumn('checador_ajuste_salida_puntual');
        });
    }
};