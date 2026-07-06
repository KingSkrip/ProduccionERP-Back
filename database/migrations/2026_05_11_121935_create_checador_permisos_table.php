<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checador_permisos', function (Blueprint $table) {
            $table->foreignId('checador_catalogo_permiso_id')->nullable()
                ->after('user_firebird_identity_id')
                ->constrained('checador_catalogo_permisos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checador_permisos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checador_catalogo_permiso_id');
        });
    }
};