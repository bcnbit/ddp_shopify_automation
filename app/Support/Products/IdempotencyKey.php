<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Enums\SyncOperation;

/**
 * Calcula la clave de idempotencia de una operación de sincronización (RFC-0004).
 *
 * idempotency_key = hash(product_id + content_version + operation)
 */
final class IdempotencyKey
{
    public static function make(int|string $productId, int|string|null $contentVersion, SyncOperation|string $operation): string
    {
        $operationValue = $operation instanceof SyncOperation ? $operation->value : $operation;

        return hash('sha256', implode('|', [
            (string) $productId,
            (string) ($contentVersion ?? '0'),
            $operationValue,
        ]));
    }
}
