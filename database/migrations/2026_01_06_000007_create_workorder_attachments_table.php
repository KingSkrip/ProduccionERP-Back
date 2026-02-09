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
        Schema::create('workorder_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workorder_id')
                ->constrained('workorders')
                ->nullable()
                ->onDelete('cascade');

            // metadata
            $table->string('disk')->default('workorders')->nullable();
            $table->string('category', 30)->nullable(); // images | documentos (o null)
            $table->string('original_name')->nullable();
            $table->string('file_name')->nullable(); // nombre guardado
            $table->string('path')->nullable(); // workorders/task/images/xxx.png
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();

            // opcional: checksum pa dedup
            $table->string('sha1', 40)->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workorder_attachments');
    }
};