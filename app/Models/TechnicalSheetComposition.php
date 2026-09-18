<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Composición reutilizable (RFC-0008).
 *
 * Guarda el texto exacto y confirmado que puede mencionarse en Shopify y en la
 * propuesta de IA: «100% algodón», «80% algodón / 20% poliéster» o «100%
 * algodón orgánico» únicamente cuando es un dato confirmado.
 *
 * Es la fuente de la que la IA deduce si puede hablar de fibra: si una ficha no
 * tiene composición, `ProductFactSheet::missingFacts()` sigue declarándola no
 * confirmada y el prompt lo prohíbe.
 */
class TechnicalSheetComposition extends TechnicalSheetEntry
{
    protected $table = 'technical_sheet_compositions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'product_type',
        'audience',
        'is_active',
        'content_text',
    ];

    protected function versionedAttributes(): array
    {
        return ['code', 'name', 'content_text'];
    }
}
