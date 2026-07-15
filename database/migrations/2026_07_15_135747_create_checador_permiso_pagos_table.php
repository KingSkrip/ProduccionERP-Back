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
        Schema::create('checador_permiso_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checador_permiso_id')->constrained('checador_permisos')->onDelete('cascade');
            $table->foreignId('checador_registro_id')->nullable()->constrained('checador_registros')->onDelete('set null');
            $table->foreignId('user_firebird_identity_id')->constrained('users_firebird_identities')->onDelete('cascade');
            $table->enum('origen', ['entrada_anticipada', 'salida_tardia'])->comment('de dónde salieron los minutos abonados');
            $table->integer('minutos_abonados');
            $table->date('fecha');
            $table->timestamps();

            $table->index(['checador_permiso_id']);
            $table->index(['user_firebird_identity_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_permiso_pagos');
    }
};