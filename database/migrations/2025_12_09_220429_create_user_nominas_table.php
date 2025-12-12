<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_nominas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onUpdate('cascade')->onDelete('cascade');
            $table->string('numero_tarjeta', 20)->nullable();
            $table->string('banco', 100)->nullable();
            $table->string('clabe_interbancaria', 18)->nullable();
            $table->decimal('salario_base', 12, 2)->nullable();
            $table->string('frecuencia_pago', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nominas');
    }
};