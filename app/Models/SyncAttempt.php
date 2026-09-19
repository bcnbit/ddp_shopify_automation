<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncOperation;
use App\Enums\SyncStatus;
use Database\Factories\SyncAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intento de sincronización con Shopify (RFC-0001 / RFC-0004).
 *
 * Guarda la petición saneada y la respuesta para que un error sea recuperable
 * y legible por la usuaria, conservando un identificador de soporte.
 */
class SyncAttempt extends Model
{
    /** @use HasFactory<SyncAttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'operation',
        'idempotency_key',
        'attempt_number',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'error_code',
        'is_retryable',
        'support_reference',
        'attempted_by',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => SyncOperation::class,
            'status' => SyncStatus::class,
            'attempt_number' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'is_retryable' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by');
    }

    /**
     * Marca el intento como en curso y guarda la petición saneada.
     *
     * La petición se persiste aquí, y no sólo la respuesta, porque cuando algo
     * falla es justo lo que hace falta para diagnosticar: sin ella, un error
     * como «File URL is invalid» decía qué se rechazó pero no **qué se envió**,
     * y había que reproducirlo a ciegas. Llega ya redactada por quien la
     * construye (ver `ProductSyncService`).
     *
     * @param  array<string, mixed>  $request
     */
    public function markRunning(array $request = []): void
    {
        $this->status = SyncStatus::Running;
        $this->started_at = now();

        if ($request !== []) {
            $this->request_payload = $request;
        }

        $this->save();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function markSucceeded(array $response = []): void
    {
        $this->status = SyncStatus::Succeeded;
        $this->response_payload = $response;
        $this->finished_at = now();
        $this->error_message = null;
        $this->error_code = null;
        $this->is_retryable = false;
        $this->save();
    }

    public function markFailed(string $message, ?string $code = null, bool $retryable = false): void
    {
        $this->status = SyncStatus::Failed;
        $this->error_message = mb_substr($message, 0, 2000);
        $this->error_code = $code;
        $this->is_retryable = $retryable;
        $this->support_reference ??= 'SYNC-'.str_pad((string) $this->getKey(), 8, '0', STR_PAD_LEFT);
        $this->finished_at = now();
        $this->save();
    }

    public function isFailed(): bool
    {
        return $this->status === SyncStatus::Failed;
    }
}
