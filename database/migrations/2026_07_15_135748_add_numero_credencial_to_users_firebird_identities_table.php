<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->string('numero_credencial', 30)->nullable()->unique()->after('checador_ajuste_salida_puntual');
        });
    }

    public function down(): void
    {
        Schema::table('users_firebird_identities', function (Blueprint $table) {
            $table->dropColumn('numero_credencial');
        });
    }
};