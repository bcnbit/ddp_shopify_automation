<?php

declare(strict_types=1);

use App\Enums\SyncStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Intento de sincronización con Shopify (RFC-0001 / RFC-0004).
     *
     * Guarda la petición saneada y la respuesta para que un error sea recuperable
     * y legible por la usuaria. `idempotency_key` se indexa pero no es única:
     * un reintento reutiliza la misma clave para que Shopify no duplique el
     * producto, y debe quedar registrado como un intento nuevo.
     */
    public function up(): void
    {
        Schema::create('sync_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('operation', 32);
            $table->char('idempotency_key', 64);
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 16)->default(SyncStatus::Pending->value);

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->boolean('is_retryable')->default(false);

            $table->string('support_reference')->nullable();

            $table->foreignId('attempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('idempotency_key');
            $table->index(['product_id', 'operation']);
            $table->index('status');
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_attempts');
    }
};
