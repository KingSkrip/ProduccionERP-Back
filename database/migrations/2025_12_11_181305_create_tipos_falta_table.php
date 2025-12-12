<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ RENOMBRADO: de 'faltas' a 'tipos_falta'
        Schema::create('tipos_falta', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_falta');
    }
};