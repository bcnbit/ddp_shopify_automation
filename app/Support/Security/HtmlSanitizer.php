<?php

declare(strict_types=1);

namespace App\Support\Security;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitiza el HTML de contenido generado o editado (RFC-0001).
 *
 * Whitelist estricta: p, ul, ol, li, strong, em, br, h2, h3.
 * Se aplica antes de guardar y antes de enviar a Shopify (RFC-0003 / RFC-0004).
 */
final class HtmlSanitizer
{
    /** @var list<string> */
    public const ALLOWED_TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'em', 'br', 'h2', 'h3'];

    private ?HTMLPurifier $purifier = null;

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = $this->purifier()->purify($html);
        $clean = trim($clean);

        return $clean === '' ? '' : $clean;
    }

    /**
     * Descarta cualquier etiqueta, atributo o script no permitido.
     */
    public function isClean(?string $html): bool
    {
        if ($html === null) {
            return true;
        }

        return $this->sanitize($html) === trim($html);
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->configuration());
    }

    private function configuration(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();

        // Whitelist de etiquetas. Los atributos se eliminan uno a uno: vaciar
        // `HTML.AllowedAttributes` haría que HTMLPurifier descartase también los
        // elementos con atributos obligatorios y emitiría avisos inútiles.
        $config->set('HTML.Allowed', implode(',', self::ALLOWED_TAGS));
        $config->set('HTML.TidyLevel', 'none');
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
        $config->set('Core.EscapeNonASCIICharacters', false);
        $config->set('Cache.DefinitionImpl', null);

        // Ningún esquema de URI: el contenido aprobado no contiene enlaces.
        $config->set('URI.AllowedSchemes', []);

        $cachePath = config('product-studio.security.html_purifier_cache_path');

        if (is_string($cachePath) && $cachePath !== '') {
            // El nombre correcto del caché es `Serializer`: con cualquier otro
            // valor HTMLPurifier avisa y cae a un caché sin directorio, lo que
            // reconstruye su definición en cada proceso (varios segundos por
            // petición). El directorio se crea si falta, porque un caché que no
            // puede escribir es peor que no tenerlo.
            if (! is_dir($cachePath)) {
                @mkdir($cachePath, 0o775, true);
            }

            if (is_dir($cachePath) && is_writable($cachePath)) {
                $config->set('Cache.DefinitionImpl', 'Serializer');
                $config->set('Cache.SerializerPath', $cachePath);
            }
        }

        return $config;
    }
}
