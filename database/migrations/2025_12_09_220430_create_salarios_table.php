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
        Schema::create('salarios', function (Blueprint $table) {
            $table->id();

            // Relación con user_nomina
            $table->foreignId('user_nomina_id')->constrained('user_nominas')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');

            $table->decimal('monto', 12, 2)->nullable();
            $table->date('fecha_pago')->nullable();
            $table->string('concepto', 150)->nullable(); // concepto del pago

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salarios');
    }
};
