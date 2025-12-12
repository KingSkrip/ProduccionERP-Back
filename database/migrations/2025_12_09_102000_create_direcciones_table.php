<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direcciones', function (Blueprint $table) {
            $table->id();
            $table->string('calle', 150)->nullable();
            $table->string('no_ext', 20)->nullable();
            $table->string('no_int', 20)->nullable();
            $table->string('colonia', 150)->nullable();
            $table->string('cp', 10)->nullable();
            $table->string('municipio', 150)->nullable();
            $table->string('estado', 150)->nullable();
            $table->string('entidad_federativa', 150)->nullable();
            $table->string('pais', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcciones');
    }
};