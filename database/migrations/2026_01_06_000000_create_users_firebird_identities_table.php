<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users_firebird_identities', function (Blueprint $table) {
            $table->id();

            // 🧑 Usuario Firebird (tabla fija USUARIOS)
            $table->unsignedBigInteger('firebird_user_clave')->nullable();

            // 👷 Registro Firebird dinámico (TBddmmyyXX)
            $table->unsignedBigInteger('firebird_tb_clave')->nullable();
            $table->string('firebird_tb_tabla', 20)->nullable();

            // 🏢 Empresa / base
            $table->char('firebird_empresa', 2)->nullable();

            // 👤 Cliente Firebird (dinámico)
            $table->string('firebird_clie_clave')->nullable();
            $table->string('firebird_clie_tabla')->nullable();

            // 👤 AGENTE Firebird (dinámico)
            $table->string('firebird_vend_clave')->nullable();
            $table->string('firebird_vend_tabla')->nullable();

            // 👤 PROVEDOR Firebird (dinámico)
            $table->string('firebird_prov_clave')->nullable();
            $table->string('firebird_prov_tabla')->nullable();
            
            // ⏱️ Auditoría mínima
            $table->timestamp('created_at')->nullable();

            // 🧠 Evita duplicados lógicos
            $table->unique([
                'firebird_empresa',
                'firebird_user_clave',
                'firebird_tb_tabla',
                'firebird_tb_clave',
            ], 'uq_user_tb_firebird');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_firebird_identities');
    }
};