<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('firebird_user_id')->index();
            $table->unsignedBigInteger('firebird_identity_id')->nullable()->index();

            $table->string('jti', 64)->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();

            $table->unsignedTinyInteger('status')
                ->default(1); // 1 = activa, 0 = cerrada

            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('logout_at')->nullable();

            $table->timestamps();

            $table->index([
                'firebird_user_id',
                'status'
            ]);

            $table->foreign('firebird_identity_id')
                ->references('id')
                ->on('users_firebird_identities')
                ->nullOnDelete();
                // Si borran el identity, la sesión no se borra,
                // solamente firebird_identity_id queda en NULL.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_sessions');
    }
};