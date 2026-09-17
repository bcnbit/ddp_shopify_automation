<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Support\Security\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Transporte GraphQL contra la API de administración de Shopify (RFC-0004).
 *
 * Única capa que conoce la versión de API, la autenticación y las cabeceras de
 * límite de llamadas. El gateway que la usa trabaja con operaciones con nombre,
 * de modo que un cambio de versión de API se resuelve aquí y en las consultas,
 * sin tocar el dominio.
 *
 * El token vive sólo en el entorno y viaja en la cabecera de autenticación;
 * nunca se registra ni se incluye en los mensajes de error.
 */
class ShopifyGraphQlClient
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function isConfigured(): bool
    {
        return trim((string) config('product-studio.shopify.shop_domain')) !== ''
            && trim((string) config('product-studio.shopify.access_token')) !== '';
    }

    /**
     * Ejecuta una operación GraphQL.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> el campo `data` de la respuesta
     *
     * @throws ShopifyRequestFailed
     */
    public function query(string $operation, string $document, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no está configurado. Avisa al administrador técnico.',
                'not_configured',
            );
        }

        try {
            // Se publica contra la URL completa en lugar de una `baseUrl` con
            // ruta vacía: al unir base y ruta vacía, Guzzle añade una barra
            // final («…/graphql.json/») y Shopify responde 404.
            $response = $this->request()->post($this->endpoint(), [
                'query' => $document,
                'variables' => (object) $variables,
            ]);
        } catch (ConnectionException $exception) {
            throw ShopifyRequestFailed::retryable(
                'No se ha podido contactar con Shopify. Se reintentará.',
                'connection_failed',
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw $this->translateHttpError($operation, $exception);
        }

        if ($response->failed()) {
            throw $this->translateHttpError($operation, new RequestException($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify ha devuelto una respuesta ilegible.',
                'invalid_response',
            );
        }

        // GraphQL devuelve 200 incluso con errores: hay que mirar `errors`.
        if (isset($body['errors']) && is_array($body['errors']) && $body['errors'] !== []) {
            throw $this->translateGraphQlErrors($operation, $body['errors']);
        }

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha devuelto datos para la operación solicitada.',
                'missing_data',
            );
        }

        return $data;
    }

    /**
     * Traduce los errores de usuario de una mutación.
     *
     * Shopify devuelve `userErrors` con el motivo real (por ejemplo, handle
     * duplicado). Sin esto, la persona vería un error genérico.
     *
     * @param  array<int, array<string, mixed>>  $userErrors
     *
     * @throws ShopifyRequestFailed
     */
    public function throwIfUserErrors(string $operation, array $userErrors): void
    {
        if ($userErrors === []) {
            return;
        }

        $messages = [];

        foreach ($userErrors as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $messages[] = $error['message'];
            }
        }

        $message = $messages === []
            ? 'Shopify ha rechazado la operación.'
            : implode(' ', $messages);

        // Un dato rechazado no se arregla reintentando: es un fallo definitivo.
        throw ShopifyRequestFailed::permanent(
            $this->redactor->redactString($message) ?? $message,
            'user_error',
        );
    }

    /**
     * Cabeceras de límite de llamadas de la última respuesta.
     *
     * Se registran para poder diagnosticar un error de throttling sin adivinar.
     *
     * @return array<string, string|null>
     */
    public function lastThrottleHeaders(): array
    {
        return $this->throttle;
    }

    /** @var array<string, string|null> */
    private array $throttle = [];

    /**
     * URL de la Admin GraphQL API para la tienda y versión configuradas.
     */
    public function endpoint(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            (string) config('product-studio.shopify.shop_domain'),
            (string) config('product-studio.shopify.api_version'),
        );
    }

    private function request(): PendingRequest
    {
        $token = (string) config('product-studio.shopify.access_token');

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout((int) config('product-studio.shopify.timeout', 30))
            ->connectTimeout((int) config('product-studio.shopify.connect_timeout', 10))
            // El throttling de Shopify se trata como recuperable: el trabajo se
            // reintenta con backoff en lugar de perder la sincronización.
            ->retry(
                (int) config('product-studio.shopify.retry_times', 2),
                (int) config('product-studio.shopify.retry_backoff_ms', 2000),
                throw: false,
            )
            ->withOptions(['on_stats' => function ($stats): void {
                $headers = $stats->getResponse()?->getHeaders() ?? [];
                $this->throttle = [
                    'call_limit' => $headers['X-Shopify-Shop-Api-Call-Limit'][0] ?? null,
                    'cost' => $headers['X-Shopify-API-Query-Cost'][0] ?? null,
                    'request_id' => $stats->getResponse()?->getHeader('X-Request-Id')[0] ?? null,
                ];
            }])
            ->asJson();
    }

    private function translateHttpError(string $operation, RequestException $exception): ShopifyRequestFailed
    {
        $status = $exception->response?->status();

        return match (true) {
            $status === 401, $status === 403 => ShopifyRequestFailed::permanent(
                'El token de Shopify no es válido o no tiene permisos suficientes. Avisa al administrador técnico.',
                'unauthorized',
                previous: $exception,
            ),
            $status === 404 => ShopifyRequestFailed::permanent(
                'La tienda o el recurso de Shopify no existe. Revisa la configuración.',
                'not_found',
                previous: $exception,
            ),
            // 429 y 430 son los códigos de límite de llamadas de Shopify.
            $status === 429, $status === 430 => ShopifyRequestFailed::retryable(
                'Shopify está limitando las llamadas. Se reintentará.',
                'throttled',
                previous: $exception,
            ),
            $status !== null && $status >= 500 => ShopifyRequestFailed::retryable(
                'Shopify no está disponible en este momento. Se reintentará.',
                'shopify_unavailable',
                previous: $exception,
            ),
            default => ShopifyRequestFailed::permanent(
                'Shopify ha devuelto un error al '.$operation.'.',
                'http_error',
                previous: $exception,
            ),
        };
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function translateGraphQlErrors(string $operation, array $errors): ShopifyRequestFailed
    {
        $messages = [];
        $isThrottled = false;

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
            $code = is_string($error['extensions']['code'] ?? null) ? $error['extensions']['code'] : null;

            if ($code === 'THROTTLED') {
                $isThrottled = true;
            }

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        $text = $messages === []
            ? 'Shopify ha devuelto un error al '.$operation.'.'
            : implode(' ', $messages);

        $text = $this->redactor->redactString($text) ?? $text;

        if ($isThrottled) {
            return ShopifyRequestFailed::retryable(
                'Shopify está limitando las llamadas. Se reintentará.',
                'throttled',
            );
        }

        return ShopifyRequestFailed::permanent($text, 'graphql_error');
    }
}
