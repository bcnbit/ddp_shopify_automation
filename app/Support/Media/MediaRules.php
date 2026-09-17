<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Validation\Rules\File;

/**
 * Reglas de validación de medios compartidas (RFC-0001 / RFC-0005).
 *
 * JPG, PNG y WebP. SVG queda expresamente rechazado en el MVP.
 */
final class MediaRules
{
    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return (array) config('product-studio.media.allowed_mime_types');
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return (array) config('product-studio.media.allowed_extensions');
    }

    public static function maxKilobytes(): int
    {
        return (int) config('product-studio.media.max_kilobytes');
    }

    public static function minWidth(): int
    {
        return (int) config('product-studio.media.min_width');
    }

    public static function minHeight(): int
    {
        return (int) config('product-studio.media.min_height');
    }

    public static function maxWidth(): int
    {
        return (int) config('product-studio.media.max_width');
    }

    public static function maxHeight(): int
    {
        return (int) config('product-studio.media.max_height');
    }

    /**
     * Reglas de Laravel para un archivo subido: MIME real, extensión y tamaño.
     *
     * @return array<int, mixed>
     */
    public static function fileValidationRules(): array
    {
        return [
            'required',
            'file',
            File::types(self::allowedExtensions())->max(self::maxKilobytes()),
            'mimetypes:'.implode(',', self::allowedMimeTypes()),
        ];
    }

    /**
     * Reglas de dimensiones aplicables tras leer el archivo (dependen del tipo real).
     *
     * @return array<int, string>
     */
    public static function dimensionValidationRules(): array
    {
        return [
            'dimensions:min_width='.self::minWidth().',min_height='.self::minHeight()
                .',max_width='.self::maxWidth().',max_height='.self::maxHeight(),
        ];
    }
}
