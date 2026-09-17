<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base de auditoría (RFC-0001 / RFC-0000).
     *
     * Registra actor, tipo de evento, entidad afectada, diff estructurado e IP.
     * `properties` pasa siempre por SecretRedactor: nunca se guarda un token,
     * una contraseña ni HTML completo.
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();

            $table->string('event', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description')->nullable();

            $table->json('properties')->nullable();

            // Se llama `changes_json` y no `changes` porque Eloquent ya usa una
            // propiedad interna `$changes` para el seguimiento de atributos
            // modificados; reutilizar ese nombre dejaría la columna inaccesible.
            $table->json('changes_json')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->uuid('request_id')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'activity_log_subject_index');
            $table->index(['user_id', 'created_at']);
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
