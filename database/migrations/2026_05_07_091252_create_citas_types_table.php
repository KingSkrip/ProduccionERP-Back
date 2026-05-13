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

        Schema::table('citas', function (Blueprint $table) {
            $table->foreignId('cita_type_id')
                ->nullable()
                ->after('id')
                ->constrained('citas_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->dropForeign(['cita_type_id']);
            $table->dropColumn('cita_type_id');
        });

        Schema::dropIfExists('citas_types');
    }
};