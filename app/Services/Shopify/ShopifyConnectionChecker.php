<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\DataObjects\Shopify\ShopifyConnectionCheck;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\ShopifyInstallation;
use Throwable;

/**
 * Comprobación de sólo lectura de la conexión con Shopify (RFC-0009 §7).
 *
 * Responde a las cuatro preguntas de la petición sin modificar nada:
 *
 * | Pregunta | Cómo |
 * |---|---|
 * | ¿El dominio es `*.myshopify.com`? | Formato + `shop.myshopifyDomain` |
 * | ¿El token está instalado correctamente? | Si la consulta pasa, el token sirve |
 * | ¿Qué scopes se han concedido? | `currentAppInstallation.accessScopes` |
 * | ¿Hay acceso a productos? | `products(first: 1)` exige `read_products` |
 *
 * La consulta es **una sola** para que no pueda convertirse en una batería de
 * llamadas por accidente, y es una `query`: no hay mutación posible. El documento
 * vive en `ShopifyOperations` como todos los demás (RFC-0004).
 */
class ShopifyConnectionChecker
{
    public function __construct(private readonly ShopifyGraphQlClient $client) {}

    /**
     * Ejecuta la comprobación y actualiza las marcas de la instalación.
     *
     * Nunca lanza: un fallo de conexión es un **resultado** que la pantalla debe
     * mostrar, no una excepción que rompa el panel.
     */
    public function check(?ShopifyInstallation $installation = null): ShopifyConnectionCheck
    {
        $installation ??= ShopifyInstallation::current();

        if ($installation === null) {
            return ShopifyConnectionCheck::failed('', 'No hay ninguna instalación de Shopify.');
        }

        $domain = $installation->validDomain();

        if ($domain === null) {
            $failed = ShopifyConnectionCheck::failed(
                (string) $installation->shop_domain,
                'El dominio guardado no tiene forma de tienda Shopify (*.myshopify.com).'
            );

            $this->remember($installation, $failed);

            return $failed;
        }

        try {
            $data = $this->client->queryFor(
                $installation,
                'ConnectionCheck',
                ShopifyOperations::CONNECTION_CHECK,
            );
        } catch (ShopifyRequestFailed $failure) {
            $failed = ShopifyConnectionCheck::failed($domain, $failure->userMessage());
            $this->remember($installation, $failed);

            return $failed;
        } catch (Throwable) {
            $failed = ShopifyConnectionCheck::failed($domain, 'No se ha podido comprobar la conexión.');
            $this->remember($installation, $failed);

            return $failed;
        }

        $check = $this->interpret($installation, $domain, $data);
        $this->remember($installation, $check);

        return $check;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function interpret(ShopifyInstallation $installation, string $domain, array $data): ShopifyConnectionCheck
    {
        $shop = is_array($data['shop'] ?? null) ? $data['shop'] : [];
        $reported = is_string($shop['myshopifyDomain'] ?? null) ? strtolower($shop['myshopifyDomain']) : null;
        $name = is_string($shop['name'] ?? null) ? $shop['name'] : null;

        // Los scopes se leen de la instalación si la consulta no los trae: Shopify
        // ya los devolvió al canjear el código y aquí sólo se confirman.
        $granted = $this->grantedScopesFrom($data) ?: $installation->grantedScopes();

        $missing = array_values(array_diff(ShopifyInstallation::REQUIRED_SCOPES, $granted));

        $products = is_array($data['products']['nodes'] ?? null) ? $data['products']['nodes'] : [];
        $productsReadable = is_array($data['products'] ?? null);

        // Se construye primero con `passed: false` y se calcula al final: así el
        // cálculo de las cuatro condiciones vive en el DTO y no se duplica aquí.
        $check = new ShopifyConnectionCheck(
            passed: false,
            shopDomain: $domain,
            shopName: $name,
            reportedDomain: $reported,
            grantedScopes: $granted,
            missingScopes: $missing,
            productsReadable: $productsReadable,
            productCount: count($products),
            errorMessage: null,
        );

        return new ShopifyConnectionCheck(
            passed: $check->domainIsValid() && $check->hasAllScopes() && $check->productsReadable,
            shopDomain: $check->shopDomain,
            shopName: $check->shopName,
            reportedDomain: $check->reportedDomain,
            grantedScopes: $check->grantedScopes,
            missingScopes: $check->missingScopes,
            productsReadable: $check->productsReadable,
            productCount: $check->productCount,
            errorMessage: null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function grantedScopesFrom(array $data): array
    {
        $scopes = $data['currentAppInstallation']['accessScopes'] ?? null;

        if (! is_array($scopes)) {
            return [];
        }

        $handles = [];

        foreach ($scopes as $scope) {
            $handle = is_array($scope) ? ($scope['handle'] ?? null) : null;

            if (is_string($handle) && $handle !== '') {
                $handles[] = $handle;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Persiste el resultado para que la pantalla muestre la última comprobación.
     *
     * El error se recorta: nunca debe contener una credencial.
     */
    private function remember(ShopifyInstallation $installation, ShopifyConnectionCheck $check): void
    {
        $installation->forceFill([
            'last_checked_at' => now(),
            'last_check_error' => $check->passed ? null : mb_substr((string) $check->errorMessage, 0, 255),
        ])->save();
    }
}
