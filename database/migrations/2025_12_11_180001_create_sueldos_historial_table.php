<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sueldos_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sueldo_id')->constrained('sueldos')->onDelete('cascade')->onUpdate('cascade');
            $table->decimal('sueldo_diario', 12, 2)->nullable();
             $table->decimal('sueldo_semanal', 12, 2)->nullable();
            $table->decimal('sueldo_mensual', 12, 2)->nullable();
            $table->decimal('sueldo_anual', 12, 2)->nullable();
            $table->text('comentarios')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sueldos_historial');
    }
};