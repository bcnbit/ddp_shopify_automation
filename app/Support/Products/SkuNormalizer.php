<?php

declare(strict_types=1);

namespace App\Support\Products;

/**
 * Normaliza SKU para que la unicidad y las comparaciones sean fiables (RFC-0005).
 *
 * El SKU original se conserva tal cual lo introduce la persona; esta forma
 * normalizada sólo se usa para índices únicos y comprobaciones de duplicado.
 * Devuelve `null` cuando no hay SKU: un borrador puede no tenerlo todavía y la
 * validación de RFC-0005 lo marca como bloqueante antes de sincronizar.
 */
final class SkuNormalizer
{
    public static function normalize(?string $sku): ?string
    {
        if ($sku === null) {
            return null;
        }

        // Espacios, guiones y guiones bajos separan igual: se unifican en "-".
        $normalized = preg_replace('/[\s\-_]+/u', '-', trim($sku)) ?? $sku;
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            return null;
        }

        return mb_strtoupper($normalized);
    }
}
