<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 20);
            $table->unsignedTinyInteger('orden')->unique(); // 1=Lunes, 7=Domingo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias');
    }
};