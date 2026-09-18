<?php

declare(strict_types=1);

namespace App\Support\Products;

/**
 * Encabezados de los bloques de ficha técnica (RFC-0008).
 *
 * Están aislados en un objeto para poder cambiarlos —o traducirlos, cuando la
 * aplicación deje de ser sólo en castellano— sin tocar el compositor.
 */
final readonly class TechnicalSheetComposerHeadings
{
    public function __construct(
        public string $composition = '<h2>Composición</h2>',
        public string $fit = '<h2>Ajuste y tallaje</h2>',
        public string $sizeGuide = '<h2>Guía de tallas</h2>',
        public string $care = '<h2>Cuidados</h2>',
    ) {}
}
