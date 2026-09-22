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
        Schema::create('citas_types', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 100);
            $table->string('slug', 100)->unique();

            $table->text('descripcion')->nullable();

            $table->string('color', 20)->nullable();
            $table->string('icono', 100)->nullable();

            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citas_types');
    }
};