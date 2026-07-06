<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_catalogo_movimientos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('clave', 20)->unique(); // ENT, SAL, SAL_COM, REG_COM, SAL_FUN, ENT_FUN
            $table->char('codigo_legado', 1)->nullable(); // TiposMov.codtipomov del viejo Geminis
            $table->boolean('cuenta_como_salida')->default(false);
            $table->boolean('cuenta_como_entrada')->default(false);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_catalogo_movimientos');
    }
};