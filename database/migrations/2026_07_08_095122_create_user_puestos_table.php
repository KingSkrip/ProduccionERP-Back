<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_puestos', function (Blueprint $table) {
            $table->id();

            // Usuario Firebird
            $table->foreignId('user_firebird_identity_id')
                ->constrained('users_firebird_identities')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Puesto
            $table->foreignId('puesto_id')
                ->constrained('puestos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Área
            $table->foreignId('area_id')
                ->nullable()
                ->constrained('areas')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Jefe directo (otro usuario Firebird)
            $table->foreignId('jefe_id')
                ->nullable()
                ->constrained('users_firebird_identities')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Un usuario solo puede tener un puesto activo
            $table->unique('user_firebird_identity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_puestos');
    }
};