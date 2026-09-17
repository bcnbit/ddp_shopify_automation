<?php

declare(strict_types=1);

namespace App\Exceptions\Shopify;

use RuntimeException;
use Throwable;

/**
 * Fallo al comunicarse con Shopify (RFC-0004).
 *
 * `isRetryable` distingue un error transitorio (límite de llamadas, 5xx,
 * timeout) de uno definitivo (token inválido, dato rechazado). Un error debe ser
 * recuperable e idempotente: nunca se reinicia el flujo, se continúa.
 */
class ShopifyRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $isRetryable = false,
        public readonly ?string $errorCode = null,
        public readonly ?string $supportReference = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(string $message, ?string $code = null, ?string $supportReference = null, ?Throwable $previous = null): self
    {
        return new self($message, true, $code, $supportReference, $previous);
    }

    public static function permanent(string $message, ?string $code = null, ?string $supportReference = null, ?Throwable $previous = null): self
    {
        return new self($message, false, $code, $supportReference, $previous);
    }

    /**
     * Mensaje listo para mostrar a la usuaria, conservando la referencia de
     * soporte para que pueda citarla (RFC-0004).
     */
    public function userMessage(): string
    {
        $base = $this->getMessage();

        if ($this->supportReference === null) {
            return $base;
        }

        return $base.' (Referencia de soporte: '.$this->supportReference.')';
    }
}