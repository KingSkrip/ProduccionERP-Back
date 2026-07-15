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
        Schema::table('checador_permisos', function (Blueprint $table) {
            $table->integer('minutos_pagados')->default(0)->after('minutos_ausencia');
        });
    }

    public function down(): void
    {
        Schema::table('checador_permisos', function (Blueprint $table) {
            $table->dropColumn('minutos_pagados');
        });
    }
};