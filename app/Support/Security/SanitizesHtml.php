<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast de Eloquent que sanitiza el HTML indicado mediante la whitelist del
 * RFC-0001: p, ul, ol, li, strong, em, br, h2, h3.
 *
 * Al ser un cast, la limpieza ocurre siempre al asignar el atributo, venga el
 * contenido de la IA o de una edición manual. No es posible guardar HTML sucio
 * aunque un llamante olvide sanitizar.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class SanitizesHtml implements CastsAttributes
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
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        return app(HtmlSanitizer::class)->sanitize($value);
    }
}
