<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Depsupervisores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('departamento_id');
            $table->unsignedBigInteger('user_id');
            $table->date('fecha_asignacion')->default(now());
            $table->timestamps();

            $table->unique(['departamento_id', 'user_id']);

            $table->foreign('departamento_id')
                ->references('id')->on('departamentos')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Depsupervisores');
    }
};
