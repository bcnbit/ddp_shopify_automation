<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\DataObjects\Shopify\ShopifyProductPayload;
use App\DataObjects\Shopify\ShopifySyncResult;
use App\Exceptions\Shopify\ShopifyRequestFailed;

/**
 * Conector de Shopify falso para las pruebas de orquestación (RFC-0004).
 *
 * El doble de HTTP sirve para verificar el contrato del conector; esto sirve para
 * verificar el **flujo**: cuántas veces se llama, con qué GID, qué pasa si el
 * primer intento falla y el segundo no. Aquí no hay red ni GraphQL.
 *
 * Registra cada llamada para poder afirmar cosas como «dos clics producen una
 * sola creación remota», que es el criterio de aceptación principal del RFC.
 */
class FakeShopifyGateway implements ShopifyProductGateway
{
    /** @var list<array{payload: ShopifyProductPayload, productGid: ?string}> */
    public array $calls = [];

    /**
     * Resultado de la siguiente llamada. Si es una excepción, se lanza y se
     * consume; después se vuelve a `$result`.
     */
    public ?ShopifyRequestFailed $failNextWith = null;

    /**
     * Excepción que se lanza siempre, hasta que se limpie.
     */
    public ?ShopifyRequestFailed $alwaysFailWith = null;

    public bool $configured = true;

    public function __construct(
        private ShopifySyncResult $result = new ShopifySyncResult(
            productGid: 'gid://shopify/Product/1234567890',
            handle: 'camiseta-marina',
            variantGids: [
                'DDP-1001-ROJO-M' => 'gid://shopify/ProductVariant/111',
                'DDP-1001-ROJO-L' => 'gid://shopify/ProductVariant/222',
            ],
        ),
        private ?ShopifySyncResult $existing = null,
    ) {}

    public function willReturn(ShopifySyncResult $result): self
    {
        $this->result = $result;

        return $this;
    }

    /**
     * Simula que Shopify ya conoce el producto, para probar la reutilización.
     */
    public function alreadyExists(ShopifySyncResult $result): self
    {
        $this->existing = $result;

        return $this;
    }

    public function createOrUpdateDraft(ShopifyProductPayload $payload, ?string $productGid = null): ShopifySyncResult
    {
        $this->calls[] = ['payload' => $payload, 'productGid' => $productGid];

        if ($this->alwaysFailWith !== null) {
            throw $this->alwaysFailWith;
        }

        if ($this->failNextWith !== null) {
            $failure = $this->failNextWith;
            $this->failNextWith = null;

            throw $failure;
        }

        // El gateway real resuelve internamente el producto ya existente cuando no
        // recibe GID (`$productGid ??= $this->findProductGid($payload)`). El doble
        // lo emula: si no hay GID pero la ficha ya está en Shopify, se actualiza
        // ese producto en lugar de crear otro. Sin esto, la prueba de «se perdió
        // la respuesta» no reproduciría el comportamiento real.
        if ($productGid === null && $this->existing !== null) {
            return $this->existing;
        }

        return $this->result;
    }

    public function findByStudioId(string $studioId): ?ShopifySyncResult
    {
        // `shopify:check` usa esta llamada para validar token y versión de API,
        // así que el doble también debe poder fallar aquí.
        if ($this->alwaysFailWith !== null) {
            throw $this->alwaysFailWith;
        }

        return $this->existing;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastPayload(): ?ShopifyProductPayload
    {
        $last = end($this->calls);

        return $last === false ? null : $last['payload'];
    }

    public function lastProductGid(): ?string
    {
        $last = end($this->calls);

        return $last === false ? null : $last['productGid'];
    }
}
