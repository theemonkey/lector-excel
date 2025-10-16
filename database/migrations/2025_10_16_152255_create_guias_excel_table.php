<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guias_excel', function (Blueprint $table) {
            $table->id();
            $table->string('numero_guia')->unique();
            $table->string('referencia')->nullable();
            $table->string('destinatario')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('direccion')->nullable();
            $table->enum('estado', ['pendiente', 'en_proceso', 'terminado', 'error', 'cancelado'])->default('pendiente');
            $table->timestamp('fecha_consulta')->nullable();
            $table->timestamp('fecha_ultima_sincronización')->nullable();
            $table->json('historial_estados')->nullable();  // Seguimiento de cambios
            $table->string('observaciones')->nullable();
            $table->string('archivo_origen')->nullable();  // Nombre del archivo Excel de origen
            $table->timestamps();

            // Indices para optimizar consultas
            $table->index(['estado', 'created_at']);
            $table->index('fecha_consulta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guias_excel');
    }
};
