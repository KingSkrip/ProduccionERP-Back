<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_turnos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_firebird_identity_id');
            $table->unsignedBigInteger('turno_id');

            // Vigencia
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            
            // Semana del año (ISO-8601: 1-53)
            $table->tinyInteger('semana_anio')->nullable();
            // ->comment('Semana del año ISO-8601 (1-53)');

            // Sobrescrituras personales (opcional)
            $table->json('dias_descanso_personalizados')->nullable();
            // ->comment('[0,6] = Domingo y Sábado');

            $table->foreignId('status_id')->constrained('statuses')->onDelete('cascade');
            $table->timestamps();

            // FK
            $table->foreign('user_firebird_identity_id')
                ->references('id')->on('users_firebird_identities')->onDelete('cascade');
            $table->foreign('turno_id')
                ->references('id')->on('turnos')->onDelete('restrict');

            $table->unique(['user_firebird_identity_id', 'status_id'], 'uq_user_turno_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_turnos');
    }
};