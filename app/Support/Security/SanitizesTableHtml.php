<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast de Eloquent que sanitiza una guía de tallas (RFC-0008).
 *
 * Al ser un cast, la limpieza ocurre siempre al asignar el atributo, venga el
 * HTML de una persona o de una importación. No es posible guardar HTML sucio
 * aunque un llamante olvide sanitizar.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class SanitizesTableHtml implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return app(TechnicalSheetHtmlSanitizer::class)->sanitize($value);
    }
}
