<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->nullable();
            $table->string('usuario', 100)->nullable();
            $table->string('curp', 150)->nullable()->unique();
            $table->string('telefono', 150)->nullable()->unique();
            $table->string('correo', 150)->nullable()->unique();
            $table->string('password')->nullable();
            // ❌ ELIMINADO: verify_field
            $table->string('photo')->nullable()->default('photos/users.jpg');

            // Foreign Keys
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->unsignedBigInteger('direccion_id')->nullable();

            $table->foreign('status_id')->references('id')->on('statuses')->onDelete('cascade');
            $table->foreign('departamento_id')->references('id')->on('departamentos')->onDelete('cascade');
            $table->foreign('direccion_id')->references('id')->on('direcciones')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};