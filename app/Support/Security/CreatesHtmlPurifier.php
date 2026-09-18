<?php

declare(strict_types=1);

namespace App\Support\Security;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Construcción del saneador de HTML con una whitelist concreta (RFC-0001 / RFC-0008).
 *
 * Existe porque hay **dos** superficies de HTML con listas blancas distintas:
 * la descripción comercial (RFC-0001) y la guía de tallas (RFC-0008). Ampliar
 * una sola lista debilitaría la descripción comercial sin necesidad, así que
 * cada consumidor declara la suya y comparte aquí la configuración del motor.
 *
 * Los atributos se eliminan siempre (no se permite ninguno). Vaciar
 * `HTML.AllowedAttributes` haría que HTMLPurifier descartase también los
 * elementos con atributos obligatorios y emitiría avisos inútiles, así que se
 * deja el mecanismo por defecto y se documenta el efecto.
 */
trait CreatesHtmlPurifier
{
    /**
     * @param  list<string>  $allowedTags
     */
    protected function createPurifier(array $allowedTags): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', implode(',', $allowedTags));
        $config->set('HTML.TidyLevel', 'none');
        $config->set('AutoFormat.RemoveEmpty', true);
        // Las celdas vacías de una tabla de tallas son información legítima
        // («esta talla no tiene medida»). Sin esta excepción, una celda que sólo
        // contuviera `&nbsp;` se eliminaría y la fila quedaría desalineada.
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp.Exceptions', ['td', 'th']);
        $config->set('Core.EscapeNonASCIICharacters', false);
        $config->set('Cache.DefinitionImpl', null);

        // Ningún esquema de URI: el contenido aprobado no contiene enlaces.
        $config->set('URI.AllowedSchemes', []);

        $cachePath = config('product-studio.security.html_purifier_cache_path');

        if (is_string($cachePath) && $cachePath !== '') {
            // El nombre correcto del caché es `Serializer`: con cualquier otro
            // valor HTMLPurifier avisa y cae a un caché sin directorio, lo que
            // reconstruye su definición en cada proceso (varios segundos por
            // petición). El directorio se crea si falta, porque un caché que no
            // puede escribir es peor que no tenerlo.
            if (! is_dir($cachePath)) {
                @mkdir($cachePath, 0o775, true);
            }

            if (is_dir($cachePath) && is_writable($cachePath)) {
                $config->set('Cache.DefinitionImpl', 'Serializer');
                $config->set('Cache.SerializerPath', $cachePath);
            }
        }

        return new HTMLPurifier($config);
    }
}
