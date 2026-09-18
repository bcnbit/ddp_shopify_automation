<?php

declare(strict_types=1);

namespace App\Support\Security;

use HTMLPurifier;

/**
 * Sanitiza el HTML de contenido generado o editado (RFC-0001).
 *
 * Whitelist estricta: p, ul, ol, li, strong, em, br, h2, h3.
 * Se aplica antes de guardar y antes de enviar a Shopify (RFC-0003 / RFC-0004).
 *
 * RFC-0008 añadió una segunda superficie de HTML (la guía de tallas) con su
 * propia lista blanca. Ampliar esta no era una opción: debilitaría la
 * descripción comercial, que no necesita tablas. Lo que ambas comparten —la
 * configuración del motor y el manejo del caché— vive en
 * `CreatesHtmlPurifier`.
 */
final class HtmlSanitizer
{
    use CreatesHtmlPurifier;

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
        return $this->purifier ??= $this->createPurifier(self::ALLOWED_TAGS);
    }
}
