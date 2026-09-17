<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\AiClient;
use App\DataObjects\Ai\AiGenerationResult;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Exceptions\Ai\AiRequestFailed;
use App\Exceptions\Ai\AiResponseRejected;
use App\Support\Ai\AiResponseValidator;
use App\Support\Ai\PromptBuilder;
use App\Support\Security\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente de OpenRouter (RFC-0003).
 *
 * OpenRouter expone una API compatible con la de OpenAI, así que el transporte
 * es una petición HTTP normal con `response_format: json_object`. El punto
 * importante es que **toda** la integración vive aquí: el dominio sólo conoce
 * `AiClient`, y cambiar de proveedor es añadir otro driver.
 *
 * La clave se lee del entorno y nunca se registra: se envía como cabecera y
 * cualquier mensaje de error pasa por el redactor de secretos.
 */
class OpenRouterClient implements AiClient
{
    public function __construct(
        private readonly AiResponseValidator $validator,
        private readonly PromptBuilder $prompts,
        private readonly SecretRedactor $redactor,
    ) {}

    public function model(): string
    {
        return (string) config('product-studio.ai.model');
    }

    public function generate(ContentGenerationRequest $request): AiGenerationResult
    {
        $apiKey = (string) config('product-studio.ai.api_key');

        if (trim($apiKey) === '') {
            throw AiRequestFailed::permanent(
                'No hay ninguna clave de OpenRouter configurada. Avisa al administrador técnico.',
                'missing_api_key',
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $this->request($apiKey)->post('/chat/completions', $this->payload($request));
        } catch (ConnectionException $exception) {
            // Timeout o DNS: transitorio, se puede reintentar.
            throw AiRequestFailed::retryable(
                'No se ha podido contactar con el proveedor de IA. Se reintentará.',
                'connection_failed',
                $exception,
            );
        } catch (RequestException $exception) {
            throw $this->translateRequestException($exception);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return $this->toResult($response, $request, $latencyMs);
    }

    private function request(string $apiKey): PendingRequest
    {
        $timeout = (int) config('product-studio.ai.timeout', 120);
        $connectTimeout = (int) config('product-studio.ai.connect_timeout', 10);
        $retries = (int) config('product-studio.ai.retry_times', 2);
        $backoff = (int) config('product-studio.ai.retry_backoff_ms', 1000);

        return Http::baseUrl((string) config('product-studio.ai.base_url'))
            ->withToken($apiKey)
            ->withHeaders([
                // Cabeceras de atribución de OpenRouter; no son credenciales.
                'HTTP-Referer' => (string) config('product-studio.ai.app_url'),
                'X-OpenRouter-Title' => (string) config('product-studio.ai.app_name'),
                'Accept' => 'application/json',
            ])
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $backoff, throw: false)
            ->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ContentGenerationRequest $request): array
    {
        return [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $this->prompts->systemPrompt($request)],
                ['role' => 'user', 'content' => $this->userContent($request)],
            ],
            // El RFC prohíbe aceptar texto libre: se pide JSON explícitamente.
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => (int) config('product-studio.ai.max_tokens', 4096),
            'temperature' => (float) config('product-studio.ai.temperature', 0.4),
        ];
    }

    /**
     * El contenido del usuario puede incluir imágenes ya redimensionadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function userContent(ContentGenerationRequest $request): array
    {
        $parts = [
            ['type' => 'text', 'text' => $this->prompts->userPrompt($request)],
        ];

        foreach ($request->imageDataUris as $dataUri) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUri],
            ];
        }

        return $parts;
    }

    private function toResult(Response $response, ContentGenerationRequest $request, int $latencyMs): AiGenerationResult
    {
        if ($response->failed()) {
            throw $this->translateResponse($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw AiResponseRejected::because(['La respuesta del proveedor no es JSON.'])->asAiRequestFailed();
        }

        $content = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw AiResponseRejected::because(['El proveedor no ha devuelto ningún contenido.'])->asAiRequestFailed();
        }

        try {
            $proposal = $this->validator->validate($this->validator->decode($content));
        } catch (AiResponseRejected $rejected) {
            Log::warning('Propuesta de IA rechazada por el validador.', [
                'product_reference' => $request->facts->internalReference,
                'reasons' => $rejected->reasons,
            ]);

            throw $rejected->asAiRequestFailed();
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiGenerationResult(
            proposal: $proposal,
            model: is_string($body['model'] ?? null) ? $body['model'] : $this->model(),
            promptVersion: PromptBuilder::VERSION,
            inputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            costEstimate: $this->estimateCost($usage),
            latencyMs: $latencyMs,
            requestId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * OpenRouter devuelve el coste real cuando la cuenta lo permite.
     *
     * @param  array<string, mixed>  $usage
     */
    private function estimateCost(array $usage): ?float
    {
        if (isset($usage['cost']) && is_numeric($usage['cost'])) {
            return (float) $usage['cost'];
        }

        return null;
    }

    private function translateRequestException(RequestException $exception): AiRequestFailed
    {
        return $this->errorForResponse($exception->response, $exception);
    }

    private function translateResponse(Response $response): AiRequestFailed
    {
        return $this->errorForResponse($response, null);
    }

    /**
     * Traduce un error HTTP a un fallo de dominio, distinguiendo lo transitorio
     * —que merece un reintento con backoff— de lo definitivo, que no debe
     * repetirse porque volvería a fallar igual.
     */
    private function errorForResponse(?Response $response, ?Throwable $previous): AiRequestFailed
    {
        $status = $response?->status();

        $message = $this->extractErrorMessage($response)
            ?? 'El proveedor de IA ha devuelto un error.';

        return match (true) {
            $status === 401, $status === 403 => AiRequestFailed::permanent(
                'La clave de OpenRouter no es válida o no tiene permisos. Avisa al administrador técnico.',
                'unauthorized',
                $previous,
            ),
            $status === 402 => AiRequestFailed::permanent(
                'La cuenta de OpenRouter no tiene saldo suficiente.',
                'insufficient_credits',
                $previous,
            ),
            $status === 429 => AiRequestFailed::retryable(
                'El proveedor de IA está limitando las peticiones. Se reintentará.',
                'rate_limited',
                $previous,
            ),
            $status !== null && $status >= 500 => AiRequestFailed::retryable(
                'El proveedor de IA no está disponible en este momento. Se reintentará.',
                'provider_unavailable',
                $previous,
            ),
            default => AiRequestFailed::permanent(
                $this->redactor->redactString($message) ?? $message,
                'request_failed',
                $previous,
            ),
        };
    }

    private function extractErrorMessage(?Response $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $body = $response->json();

        if (is_array($body) && is_string($body['error']['message'] ?? null)) {
            return $this->redactor->redactString($body['error']['message']);
        }

        if (is_array($body) && is_string($body['message'] ?? null)) {
            return $this->redactor->redactString($body['message']);
        }

        return null;
    }
}
