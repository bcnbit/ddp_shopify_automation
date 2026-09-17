<?php

declare(strict_types=1);

namespace App\Support\Products;

/**
 * Genera la matriz de variantes color × talla (RFC-0002).
 *
 * La fuente de verdad sigue siendo la tabla local de variantes: esto sólo
 * produce las combinaciones que la usuaria confirma, sin inventar SKU ni precio
 * (RFC-0003 prohíbe que la IA afirme datos comerciales).
 */
final class VariantMatrix
{
    /**
     * Producto cartesiano de colores y tallas.
     *
     * @param  list<string>  $colors
     * @param  list<string>  $sizes
     * @return list<array{color: string, size: string}>
     */
    public static function combinations(array $colors, array $sizes): array
    {
        $colors = self::clean($colors);
        $sizes = self::clean($sizes);

        if ($colors === [] && $sizes === []) {
            return [];
        }

        // Sin uno de los ejes, cada valor del otro es una variante por sí mismo.
        $colors = $colors === [] ? [''] : $colors;
        $sizes = $sizes === [] ? [''] : $sizes;

        $combinations = [];

        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                $combinations[] = ['color' => $color, 'size' => $size];
            }
        }

        return $combinations;
    }

    /**
     * SKU sugerido a partir de la referencia de la ficha.
     *
     * Es una propuesta editable: la persona puede sobrescribir cualquier SKU, y
     * la validación de RFC-0005 sigue siendo la que decide si es aceptable.
     */
    public static function suggestSku(string $internalReference, string $color, string $size): string
    {
        $parts = array_filter([
            SkuNormalizer::normalize($internalReference),
            self::abbreviate($color),
            $size === '' ? null : mb_strtoupper(trim($size)),
        ]);

        return implode('-', $parts);
    }

    /**
     * Abreviatura estable para colores compuestos: "Azul marino" -> "AM".
     */
    private static function abbreviate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = SkuNormalizer::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $words = explode('-', $normalized);

        if (count($words) === 1) {
            return mb_substr($words[0], 0, 3);
        }

        $initials = '';

        foreach ($words as $word) {
            $initials .= mb_substr($word, 0, 1);
        }

        return $initials;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || in_array($trimmed, $result, true)) {
                continue;
            }

            $result[] = $trimmed;
        }

        return $result;
    }
}
