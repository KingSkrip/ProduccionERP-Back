<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ RENOMBRADO: de 'faltas_historial' a 'ausencias'
        Schema::create('ausencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tipo_falta_id')->nullable();
            $table->unsignedBigInteger('tipo_afectacion_id')->nullable();
            $table->date('fecha')->nullable();
            $table->text('comentarios')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tipo_falta_id')->references('id')->on('tipos_falta');
            $table->foreign('tipo_afectacion_id')->references('id')->on('tipo_afectaciones');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ausencias');
    }
};