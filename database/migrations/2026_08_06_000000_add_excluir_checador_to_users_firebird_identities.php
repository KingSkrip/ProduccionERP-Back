<?php
// database/migrations/2026_08_06_000000_add_excluir_checador_to_users_firebird_identities.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->boolean('excluir_checador')
                ->default(false)
                ->after('numero_credencial')
                ->comment('Si es true, esta identidad no debe generar QR ni registrar checador (cuentas de sistema/kiosco).');
        });
    }

    public function down(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->dropColumn('excluir_checador');
        });
    }
};