<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_statuses', function (Blueprint $table) {
            $table->id();
            
            // ❌ ELIMINADO: nombre
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->foreign('status_id')->references('id')->on('statuses')->onUpdate('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
            $table->unique(['status_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_statuses');
    }
};