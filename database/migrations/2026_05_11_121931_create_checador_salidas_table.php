<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_salidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checador_registro_id')->nullable()
                ->constrained('checador_registros')->nullOnDelete();
            $table->foreignId('checador_entrada_id')->nullable()
                ->constrained('checador_entradas')->nullOnDelete();
            $table->foreignId('user_firebird_identity_id')
                ->constrained('users_firebird_identities')
                ->onDelete('cascade');
            $table->char('firebird_empresa', 2)->nullable();
            $table->foreignId('turno_id')->nullable()->constrained('turnos')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora_salida');
            $table->time('hora_programada')->nullable();
            $table->integer('minutos_anticipacion')->default(0);
            $table->decimal('horas_extra', 5, 2)->default(0);
            $table->timestamps();

            $table->index(['user_firebird_identity_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_salidas');
    }
};