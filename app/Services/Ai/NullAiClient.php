<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\AiClient;
use App\DataObjects\Ai\AiGenerationResult;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Exceptions\Ai\AiRequestFailed;

/**
 * Driver de IA desactivado (RFC-0003).
 *
 * Es el valor por defecto cuando no hay proveedor configurado, para que la
 * aplicación funcione y las pruebas sean deterministas sin red. Falla de forma
 * explícita y comprensible en lugar de intentar una llamada que no puede salir.
 */
class NullAiClient implements AiClient
{
    public function model(): string
    {
        return 'null';
    }

    public function generate(ContentGenerationRequest $request): AiGenerationResult
    {
        throw AiRequestFailed::permanent(
            'La generación con IA está desactivada. Configura OPENROUTER_API_KEY para activarla.',
            'ai_disabled',
        );
    }
}
