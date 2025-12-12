<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacacion_historials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacacion_id')->constrained('vacaciones')->onDelete('cascade');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->integer('dias')->nullable();
            $table->text('comentarios')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacacion_historials');
    }
};