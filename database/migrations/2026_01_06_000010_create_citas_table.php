<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->id();

            // 🔗 Relaciones
            $table->unsignedBigInteger('id_user')->nullable();
            $table->unsignedBigInteger('id_visitante')->nullable();
            $table->string('nombre_visitante', 255)->nullable();
            // 📅 Datos de la cita
            $table->date('fecha')->nullable();
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();

            $table->string('motivo', 255)->nullable();

            $table->enum('estado', ['pendiente', 'confirmada', 'cancelada'])
                ->default('pendiente')
                ->nullable();

            $table->text('notas')->nullable();

            $table->boolean('recordatorio_30min')->default(false);
            $table->boolean('recordatorio_60min')->default(false);

            $table->timestamps(); // created_at & updated_at

            // 🔐 Foreign Keys
            $table->foreign('id_user')
                ->references('id')
                ->on('users_firebird_identities')
                ->onDelete('cascade');

            $table->foreign('id_visitante')
                ->references('id')
                ->on('users_firebird_identities')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};