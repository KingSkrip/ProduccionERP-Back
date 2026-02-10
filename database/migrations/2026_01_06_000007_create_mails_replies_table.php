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
        Schema::create('mails_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workorder_id')
                ->constrained('workorders')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users_firebird_identities')
                ->cascadeOnDelete();

            $table->foreignId('reply_to_id')
                ->nullable()
                ->constrained('mails_replies')
                ->nullOnDelete();

            // Tipo de respuesta
            $table->enum('reply_type', [
                'reply',
                'reply_all'
            ])->default('reply');

            // Contenido
            $table->text('body')->nullable();

            // Momento del envío
            $table->timestamp('sent_at')->useCurrent();

            $table->timestamps();

            $table->index(['workorder_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mails_replies');
    }
};