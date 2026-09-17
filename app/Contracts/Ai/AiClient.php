<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\DataObjects\Ai\AiGenerationResult;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Exceptions\Ai\AiRequestFailed;

/**
 * Contrato del proveedor de IA (RFC-0003).
 *
 * Ninguna pantalla ni servicio de dominio llama al proveedor directamente: todo
 * pasa por esta interfaz, de modo que cambiar de proveedor (OpenRouter hoy, otro
 * mañana) no toque el dominio. El driver se elige por configuración.
 */
interface AiClient
{
    /**
     * Genera una propuesta de contenido a partir de datos confirmados.
     *
     * Debe lanzar `AiRequestFailed` ante un fallo transitorio o definitivo; no
     * devuelve nunca una propuesta parcialmente válida.
     *
     * @throws AiRequestFailed
     */
    public function generate(ContentGenerationRequest $request): AiGenerationResult;

    /** Identificador del modelo realmente usado, para trazabilidad. */
    public function model(): string;
}
