<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Perfil de cuidados reutilizable (RFC-0008).
 *
 * El texto se guarda estructurado: una instrucción por línea. Así el compositor
 * puede emitir un párrafo por instrucción en lugar de un bloque indivisible, y
 * una persona puede reordenar o quitar una instrucción sin reescribir el resto.
 */
class TechnicalSheetCare extends TechnicalSheetEntry
{
    protected $table = 'technical_sheet_cares';

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

    /**
     * Instrucciones de cuidado, una por línea no vacía.
     *
     * @return list<string>
     */
    public function instructions(): array
    {
        $lines = preg_split('/\R/u', (string) $this->content_text) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
