<?php

declare(strict_types=1);

namespace App\DataObjects\Shopify;

/**
 * Resultado de una operación contra Shopify (RFC-0004).
 *
 * Se guardan los GID devueltos en local para que la siguiente sincronización
 * actualice el mismo producto en lugar de crear otro.
 */
final readonly class ShopifySyncResult
{
    /**
     * @param  array<int, string>  $variantGids por `sku` => GID
     * @param  array<int, string>  $mediaGids por `sha256` => GID
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $productGid,
        public ?string $handle = null,
        public array $variantGids = [],
        public array $mediaGids = [],
        public ?string $userErrors = null,
        public array $raw = [],
    ) {
    }

    public function numericId(): ?string
    {
        if (preg_match('#/(\d+)$#', $this->productGid, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Representación que se guarda en `sync_attempts.response_payload`.
     *
     * No incluye `raw`: la respuesta completa de Shopify es voluminosa y no
     * aporta nada a la persona que revisa un error.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_gid' => $this->productGid,
            'handle' => $this->handle,
            'variant_gids' => $this->variantGids,
            'media_gids' => $this->mediaGids,
            'user_errors' => $this->userErrors,
        ];
    }
}