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
        Schema::create('user_nominas', function (Blueprint $table) {
            $table->id();

            // Relación con usuarios
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');

            $table->string('numero_tarjeta', 20)->nullable();
            $table->string('banco', 100)->nullable();
            $table->string('clabe_interbancaria', 18)->nullable();
            $table->decimal('salario_base', 12, 2)->nullable();
            $table->string('frecuencia_pago', 50)->nullable(); // ej: mensual, quincenal

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_nominas');
    }
};
