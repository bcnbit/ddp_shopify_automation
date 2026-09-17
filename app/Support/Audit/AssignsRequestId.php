<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asigna el contexto de la petición (RFC-0007).
 *
 * El `request_id` se propaga por cabecera y se guarda junto a la IP y el agente
 * del usuario para correlacionar logs, auditoría y errores. Al vivir en los
 * atributos de la petición, una ejecución por consola no inventa una IP.
 */
class AssignsRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('client_ip', $request->ip());
        $request->attributes->set('client_user_agent', $request->userAgent());

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
