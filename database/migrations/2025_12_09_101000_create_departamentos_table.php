<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departamentos', function (Blueprint $table) {
            $table->id(); // PK
            $table->string('nombre', 150)->nullable();
            $table->string('cuenta_coi', 50)->nullable();
            $table->string('clasificacion', 50)->nullable();
            $table->string('costo', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departamentos');
    }
};
