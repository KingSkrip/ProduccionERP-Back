<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workorders', function (Blueprint $table) {
            $table->string('ticket_number')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('priority_id')->nullable()->after('status_id');
            $table->foreign('priority_id')
                ->references('id')
                ->on('priorities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workorders', function (Blueprint $table) {
            $table->dropColumn('recordatorio_pendiente');
        });
    }
};