<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->id();
            
            // ✅ CORREGIDO: role_id y user_id (antes role_clave y model_clave)
            $table->unsignedBigInteger('role_clave')->nullable();
            $table->unsignedBigInteger('model_clave')->nullable();
            $table->unsignedBigInteger('subrol_id')->nullable();
            $table->string('model_type', 150)->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('role_clave')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('model_clave')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subrol_id')->references('id')->on('subroles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};