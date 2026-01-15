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

            // 🔐 Rol y subrol
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('subrol_id')->nullable();

            // 🔗 Identidad Firebird (PIVOTE)
            $table->unsignedBigInteger('firebird_identity_id');

            // 🧠 Polimorfismo futuro (opcional pero bien)
            $table->string('model_type', 150)->default('firebird_identity');

            $table->timestamps();

            // 🔒 Foreign keys
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->onDelete('cascade');

            $table->foreign('subrol_id')
                ->references('id')
                ->on('subroles')
                ->onDelete('cascade');

            $table->foreign('firebird_identity_id')
                ->references('id')
                ->on('users_firebird_identities')
                ->onDelete('cascade');

            // 🚫 Evitar duplicados
            $table->unique(
                ['role_id', 'subrol_id', 'firebird_identity_id'],
                'uq_role_identity'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
