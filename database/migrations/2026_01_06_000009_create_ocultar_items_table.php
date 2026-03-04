<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ocultar', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users_firebird_identities')
                ->cascadeOnDelete();

            $table->string('z200_id'); // o cliente_id, pedido_id, etc

            $table->boolean('oculto')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'z200_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocultar');
    }
};