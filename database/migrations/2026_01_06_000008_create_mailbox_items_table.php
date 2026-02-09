<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mailbox_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workorder_id')
                ->constrained('workorders')
                ->cascadeOnDelete()->nullable();

            $table->foreignId('user_id')
                ->constrained('users_firebird_identities')
                ->cascadeOnDelete()->nullable();

            // Bandeja
            $table->enum('folder', [
                'inbox',
                'sent',
                'drafts',
                'trash',
                'spam'
            ])->default('inbox')->nullable();

            // Flags
            $table->boolean('is_starred')->default(false)->nullable();
            $table->boolean('is_important')->default(false)->nullable();

            // Estados
            $table->timestamp('read_at')->nullable();
            $table->timestamp('trashed_at')->nullable();

            $table->timestamps();

            // Un estado por usuario por mensaje
            $table->unique(['workorder_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_items');
    }
};