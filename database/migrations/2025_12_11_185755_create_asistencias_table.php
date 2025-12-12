<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('turno_id')->nullable();
            $table->date('fecha')->nullable();
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->timestamps();

            $table->foreign('turno_id')->references('id')->on('turnos')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
