<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Perfil de ajuste y tallaje reutilizable (RFC-0008).
 *
 * Contiene un nombre y un texto comercial o técnico ya aprobado: «Unisex
 * regular», «Mujer entallada», «Oversize», «Corte recto». Es el dato que
 * respalda las afirmaciones de tallaje que RFC-0003 prohíbe inventar.
 */
class TechnicalSheetFit extends TechnicalSheetEntry
{
    protected $table = 'technical_sheet_fits';

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
