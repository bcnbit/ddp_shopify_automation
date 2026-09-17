<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

use App\DataObjects\Shopify\ShopifyProductPayload;
use App\DataObjects\Shopify\ShopifySyncResult;
use App\Exceptions\Shopify\ShopifyRequestFailed;

/**
 * Contrato del conector con Shopify (RFC-0004).
 *
 * Ninguna pantalla llama a la API directamente: todo pasa por aquí. Esto es lo
 * que permite encapsular las mutaciones de Admin GraphQL para que un cambio de
 * versión de API no afecte al dominio de la aplicación.
 */
interface ShopifyProductGateway
{
    /**
     * Crea o actualiza el producto. **Siempre** con estado DRAFT.
     *
     * Si `$productGid` viene informado, actualiza ese producto en lugar de crear
     * uno nuevo: es la garantía de que un doble clic no duplica el producto.
     *
     * @throws ShopifyRequestFailed
     */
    public function createOrUpdateDraft(ShopifyProductPayload $payload, ?string $productGid = null): ShopifySyncResult;

    /**
     * Busca un producto por el metafield privado `product_studio_id`.
     *
     * Se usa antes de crear para no duplicar si una ejecución anterior ya lo
     * había creado pero se perdió la respuesta.
     *
     * @throws ShopifyRequestFailed
     */
    public function findByStudioId(string $studioId): ?ShopifySyncResult;

    /**
     * Indica si el conector está configurado. Permite avisar en lugar de fallar.
     */
    public function isConfigured(): bool;
}
