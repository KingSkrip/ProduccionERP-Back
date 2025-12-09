<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subroles', function (Blueprint $table) {
            $table->id(); // PK obligatoria
            $table->string('nombre', 150)->nullable();
            $table->string('guard_name', 100)->nullable();
            $table->timestamps(); // created_at y updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subroles');
    }
};
