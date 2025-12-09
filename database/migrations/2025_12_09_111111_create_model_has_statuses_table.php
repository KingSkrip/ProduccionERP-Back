<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_statuses', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id(); // PK

            $table->string('nombre', 100)->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Claves foráneas
            $table->foreign('status_id')
                ->references('id')->on('statuses')
                ->onUpdate('cascade')
                ->onDelete('restrict'); // evita eliminar un status si se usa

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade'); // elimina registros si se borra usuario

            $table->timestamps();

            // Índice compuesto opcional para consultas rápidas
            $table->unique(['status_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_statuses');
    }
};
