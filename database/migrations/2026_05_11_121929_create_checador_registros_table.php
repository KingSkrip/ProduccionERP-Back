<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_firebird_identity_id')
                ->constrained('users_firebird_identities')
                ->onDelete('cascade');
            $table->char('firebird_empresa', 2)->nullable();
            $table->foreignId('turno_id')->nullable()->constrained('turnos')->nullOnDelete();
            $table->enum('tipo', ['entrada', 'salida']);
            $table->date('fecha');
            $table->time('hora');
            $table->dateTime('fecha_hora');
            $table->enum('metodo', ['qr', 'manual'])->default('qr');
            $table->string('ip_address', 45)->nullable();
            $table->string('dispositivo')->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->boolean('valido')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['user_firebird_identity_id', 'fecha']);
            $table->index(['firebird_empresa', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_registros');
    }
};
