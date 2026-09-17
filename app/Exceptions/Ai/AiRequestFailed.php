<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * Fallo al obtener una propuesta de IA (RFC-0003).
 *
 * `isRetryable` distingue un error transitorio (límite de peticiones, timeout,
 * 5xx) de uno definitivo (clave inválida, petición mal formada, contenido
 * rechazado), porque sólo el primero debe reintentarse.
 */
class AiRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $isRetryable = false,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(string $message, ?string $code = null, ?Throwable $previous = null): self
    {
        return new self($message, true, $code, $previous);
    }

    public static function permanent(string $message, ?string $code = null, ?Throwable $previous = null): self
    {
        return new self($message, false, $code, $previous);
    }
}
