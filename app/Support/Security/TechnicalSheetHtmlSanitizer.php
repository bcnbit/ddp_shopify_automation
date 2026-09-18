<?php

declare(strict_types=1);

namespace App\Support\Security;

use HTMLPurifier;

/**
 * Sanitiza el HTML de una guía de tallas (RFC-0008).
 *
 * Whitelist mínima y propia, distinta de la de la descripción comercial:
 * `table`, `thead`, `tbody`, `tr`, `th`, `td`, `p`, `strong`, `em`, `br`, `ul`,
 * `ol`, `li`.
 *
 * La lista blanca **no incluye atributos**: `colspan` y `rowspan` se pierden al
 * sanear, lo que puede cambiar el significado de una tabla. Para que eso no
 * ocurra en silencio, `losesTableCellSpans()` permite avisar en el panel.
 */
final class TechnicalSheetHtmlSanitizer
{
    use CreatesHtmlPurifier;

    /** @var list<string> */
    public const ALLOWED_TAGS = [
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'p', 'strong', 'em', 'br', 'ul', 'ol', 'li',
    ];

    private ?HTMLPurifier $purifier = null;

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = trim($this->purifier()->purify($html));

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

    /**
     * ¿El HTML recibido usa atributos de combinación de celdas?
     *
     * No se llama «error» porque la lista blanca del RFC no los permite: sólo
     * sirve para avisar a la persona de que la tabla perderá esas marcas.
     */
    public function losesTableCellSpans(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        return preg_match('/\s(colspan|rowspan)\s*=/i', $html) === 1;
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= $this->createPurifier(self::ALLOWED_TAGS);
    }
}
