<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->nullable();
            $table->string('token', 100)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};