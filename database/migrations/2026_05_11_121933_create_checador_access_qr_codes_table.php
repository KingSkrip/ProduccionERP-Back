<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checador_access_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_firebird_identity_id')
                ->unique()
                ->constrained('users_firebird_identities')
                ->onDelete('cascade');
            $table->char('firebird_empresa', 2)->nullable();
            $table->string('token', 64)->unique();
            $table->text('payload')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('ultima_lectura')->nullable();
            $table->timestamps();

            $table->index(['token', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checador_access_qr_codes');
    }
};