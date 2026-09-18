<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Security\SanitizesTableHtml;

/**
 * Guía de tallas reutilizable (RFC-0008).
 *
 * Guarda una nota introductoria opcional, la tabla HTML y una nota final
 * opcional. El HTML pasa por la whitelist de tablas de RFC-0008 al guardarse,
 * mediante un cast: no existe ninguna ruta que pueda persistir una tabla con
 * scripts, estilos o atributos.
 */
class TechnicalSheetSizeGuide extends TechnicalSheetEntry
{
    protected $table = 'technical_sheet_size_guides';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'product_type',
        'audience',
        'is_active',
        'intro_note',
        'content_html',
        'closing_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'content_html' => SanitizesTableHtml::class,
        ];
    }

    protected function versionedAttributes(): array
    {
        return ['code', 'name', 'content_html', 'intro_note', 'closing_note'];
    }
}
