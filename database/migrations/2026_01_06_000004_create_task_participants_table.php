<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_participants', function (Blueprint $table) {
    $table->id();

    $table->foreignId('workorder_id')
        ->constrained('workorders')
        ->onDelete('cascade');

    $table->foreignId('user_id')
        ->constrained('users_firebird_identities')
        ->onDelete('cascade');

    $table->string('role', 50); 
    // approver | assignee | watcher | reviewer

    $table->foreignId('status_id')
        ->nullable()
        ->constrained('statuses')
        ->onDelete('set null');

    $table->text('comentarios')->nullable();
    $table->dateTime('fecha_accion')->nullable();
    $table->integer('orden')->nullable();

    $table->timestamps();

    $table->unique(['workorder_id', 'user_id', 'role']);
});

    }

    public function down(): void
    {
        Schema::dropIfExists('task_participants');
    }
};