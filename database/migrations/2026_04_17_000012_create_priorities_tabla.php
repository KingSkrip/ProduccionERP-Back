<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();           // 'Baja', 'Media', 'Alta', 'Crítica'
            $table->string('slug')->unique()->nullable();  // 'low', 'medium', 'high', 'critical'
            $table->string('color')->nullable(); // '#22c55e', '#f59e0b', '#ef4444'
            $table->integer('level')->default(0)->nullable(); // para ordenar: 0=baja, 3=crítica
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priorities');
    }
};
