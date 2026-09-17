<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * La respuesta de la IA no cumple el contrato (RFC-0003).
 *
 * Es un fallo definitivo: reintentar con la misma entrada produciría la misma
 * respuesta inválida. Se conserva el motivo para que la usuaria sepa qué pasó.
 */
class AiResponseRejected extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        string $message,
        public readonly array $reasons = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self(
            'La propuesta generada no cumple el contrato: '.implode(' ', $reasons),
            $reasons,
        );
    }

    public function asAiRequestFailed(): AiRequestFailed
    {
        return AiRequestFailed::permanent($this->getMessage(), 'invalid_response', $this);
    }
}
