<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Sustituye credenciales y datos sensibles antes de registrarlos (RFC-0001).
 *
 * Se aplica a activity_log, sync_attempts y a cualquier entrada de log.
 */
final class SecretRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * Nombres de clave cuyo valor nunca debe persistirse ni registrarse.
     *
     * @var list<string>
     */
    public const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'secret',
        'token',
        'key',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'authorization',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'remember_token',
        'shopify_access_token',
        'shopify_api_token',
        'openrouter_api_key',
        'openai_api_key',
        'client_secret',
        'private_key',
        'webhook_secret',
    ];

    /**
     * Claves que pueden contener imágenes o HTML voluminoso y no deben registrarse (RFC-0001).
     *
     * @var list<string>
     */
    public const OVERSIZED_KEYS = [
        'image_base64',
        'images_base64',
        'b64_json',
        'html_description',
        // La descripción que viaja a Shopify se llama `description_html` (el
        // orden de las palabras se invierte al construir la carga útil). Sin
        // esta entrada, la petición guardada en `sync_attempts` arrastraba el
        // HTML compuesto entero, que puede ser largo y no aporta al diagnóstico.
        'description_html',
        'raw_response',
        'prompt_text',
        'full_prompt',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);

            if ($this->isSensitive($normalized)) {
                $result[$key] = self::MASK;

                continue;
            }

            if ($this->isOversized($normalized)) {
                $result[$key] = is_string($value)
                    ? self::MASK.' ('.$this->lengthOf($value).' bytes)'
                    : self::MASK;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    /**
     * Redacta patrones sensibles dentro de un texto libre (mensajes de error, cabeceras).
     */
    public function redactString(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // El orden importa: primero se retira el esquema `Bearer` junto con su
        // token, y sólo después se enmascara el valor de una clave sensible.
        // Así `Authorization: Bearer abc123` no deja el token suelto al
        // cortarse el valor en el primer espacio.
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
            '/\bshpat_[A-Za-z0-9]{8,}\b/',
            '/\bshpca_[A-Za-z0-9]{8,}\b/',
            // La API secret key de una app de Shopify (`shpss_`) no es un access
            // token, pero es una credencial con la que se firman los intercambios
            // OAuth: si aparece en un mensaje de error, debe quedar enmascarada
            // igual que un token (RFC-0009).
            '/\bshpss_[A-Za-z0-9]{8,}\b/',
            '/\bsk-[A-Za-z0-9\-_]{16,}\b/',
            '/\bsk-or-v1-[A-Za-z0-9]{16,}\b/',
            '/\beyJ[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}\b/',
        ];

        foreach (self::SENSITIVE_KEYS as $key) {
            $patterns[] = '/\b'.preg_quote($key, '/').'\s*[:=]\s*(?:Bearer\s+)?["\']?[^"\'\s,;&]+/i';
        }

        return preg_replace($patterns, self::MASK, $text) ?? $text;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($this->keyMatches($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function isOversized(string $key): bool
    {
        foreach (self::OVERSIZED_KEYS as $oversized) {
            if ($this->keyMatches($key, $oversized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compara una clave con un término sensible en límites de palabra.
     *
     * No se usa `str_contains` a propósito: haría que `input_tokens` o
     * `prompt_version` coincidiesen con `token` y `prompt`, y la auditoría de
     * coste quedaría enmascarada justo donde se necesita leerla. Se exige
     * coincidencia exacta o que el término sea una palabra completa del nombre.
     */
    private function keyMatches(string $key, string $term): bool
    {
        if ($key === $term) {
            return true;
        }

        return str_ends_with($key, '_'.$term)
            || str_ends_with($key, '-'.$term)
            || str_starts_with($key, $term.'_')
            || str_starts_with($key, $term.'-');
    }

    private function lengthOf(string $value): int
    {
        return strlen($value);
    }
}
