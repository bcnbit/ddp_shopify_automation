<?php

declare(strict_types=1);

namespace App\Exceptions\Shopify;

use RuntimeException;

/**
 * Fallo en el flujo de instalación OAuth (RFC-0009).
 *
 * Es una excepción propia y no `ShopifyRequestFailed` porque ocurre **antes** de
 * tener token: no hay nada que reintentar ni cabecera que enviar. El mensaje está
 * pensado para mostrarse a una persona, sin ninguna credencial dentro.
 */
class ShopifyOAuthFailed extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'Falta configurar la aplicación de Shopify. Rellena SHOPIFY_API_KEY y '
            .'SHOPIFY_API_SECRET en el entorno (nunca en Git).'
        );
    }

    public static function invalidShopDomain(string $domain): self
    {
        return new self(
            "El dominio «{$domain}» no tiene forma de tienda Shopify. Debe terminar en .myshopify.com."
        );
    }

    public static function invalidState(): self
    {
        return new self(
            'La verificación de seguridad de la instalación no coincide o ha caducado. '
            .'Vuelve a empezar la conexión desde el panel.'
        );
    }

    public static function invalidHmac(): self
    {
        return new self(
            'Shopify no ha podido confirmar la autenticidad de la respuesta. '
            .'Vuelve a empezar la conexión desde el panel.'
        );
    }

    public static function exchangeFailed(string $reason): self
    {
        return new self('Shopify no ha entregado el token de acceso: '.$reason);
    }

    /**
     * El caso que motivó la RFC: una client secret puesta donde va un token.
     */
    public static function clientSecretIsNotAnAccessToken(): self
    {
        return new self(
            'La credencial guardada es la API secret key de la aplicación (shpss_), no un '
            .'access token. Esa clave no se envía en X-Shopify-Access-Token: instala la '
            .'aplicación por OAuth para obtener un token shpat_.'
        );
    }

    public static function unusableAccessToken(): self
    {
        return new self(
            'Shopify ha devuelto una credencial que no es un access token válido. '
            .'Vuelve a instalar la aplicación desde el panel.'
        );
    }

    public static function missingScopes(array $missing): self
    {
        return new self(
            'La instalación no ha concedido todos los permisos necesarios. Faltan: '
            .implode(', ', $missing).'. Reinstala la aplicación aceptando los permisos.'
        );
    }
}
