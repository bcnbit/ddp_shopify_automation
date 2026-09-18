<?php

declare(strict_types=1);

namespace App\DataObjects\Shopify;

use App\Enums\InventoryPolicy;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Support\Products\TechnicalSheetComposer;

/**
 * Carga útil para Shopify (RFC-0004).
 *
 * Se construye a partir de datos **ya aprobados** por una persona. Incluye el
 * `studioId` que se escribirá como metafield privado para poder reconocer el
 * producto en el futuro sin duplicarlo.
 *
 * Los medios llevan `disk` y `path` porque el gateway necesita el archivo real
 * para subirlo: Shopify no puede descargar de un disco privado. El `sha256`
 * viaja aparte para poder reconocer una imagen ya subida y no repetirla.
 *
 * Desde RFC-0008 la descripción no es sólo el texto comercial: se compone con
 * TechnicalSheetComposer, que añade composición, ajuste, guía de tallas y
 * cuidados en ese orden. El compositor es el mismo que alimenta la
 * previsualización del panel, para que lo que la persona ve sea exactamente lo
 * que recibe la tienda.
 */
final readonly class ShopifyProductPayload
{
    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<array<string, mixed>>  $media
     * @param  list<string>  $tags
     * @param  list<array<string, mixed>>  $metafields
     */
    public function __construct(
        public string $studioId,
        public string $title,
        public string $descriptionHtml,
        public string $handle,
        public string $status,
        public string $vendor,
        public string $productType,
        public array $variants,
        public array $media,
        public array $tags,
        public ?string $seoTitle = null,
        public ?string $seoDescription = null,
        public ?string $categoryTaxonomyId = null,
        public array $metafields = [],
    ) {}

    /**
     * Construye la carga útil desde una ficha local.
     *
     * El estado es siempre `DRAFT`: es un principio no negociable del RFC-0000 y
     * aquí no se permite sobrescribirlo.
     */
    public static function fromProduct(Product $product): self
    {
        $content = $product->contentFor();

        $variants = $product->variants->map(static fn (ProductVariant $variant): array => [
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $variant->effectivePrice(),
            'compareAtPrice' => $variant->compare_at_price,
            'inventoryPolicy' => $variant->inventory_policy instanceof InventoryPolicy
                ? mb_strtoupper($variant->inventory_policy->value)
                : mb_strtoupper((string) $variant->inventory_policy),
            'option1Name' => $variant->option1_name,
            'option1Value' => $variant->option1_value,
            'option2Name' => $variant->option2_name,
            'option2Value' => $variant->option2_value,
            'position' => $variant->position,
            'shopifyVariantGid' => $variant->shopify_variant_gid,
        ])->all();

        $media = $product->media->map(static fn (ProductMedia $item): array => [
            'mediaId' => (int) $item->getKey(),
            'disk' => $item->disk,
            'path' => $item->path,
            'originalFilename' => $item->original_filename,
            'mimeType' => $item->mime_type,
            'altText' => $item->alt_text,
            'isPrimary' => (bool) $item->is_primary,
            'sortOrder' => (int) $item->sort_order,
            'sha256' => $item->sha256,
            'shopifyMediaGid' => $item->shopify_media_gid,
        ])->all();

        return new self(
            studioId: (string) $product->internal_reference,
            title: (string) ($content?->title ?? $product->source_name),
            descriptionHtml: (new TechnicalSheetComposer)->compose(
                $product,
                $content?->html_description,
            ),
            handle: (string) ($content?->handle ?? $product->shopify_handle ?? ''),
            status: 'DRAFT',
            vendor: (string) ($product->brand ?? ''),
            productType: $product->product_type?->value ?? '',
            variants: $variants,
            media: $media,
            tags: $content?->tags() ?? [],
            seoTitle: $content?->seo_title,
            seoDescription: $content?->seo_description,
            categoryTaxonomyId: $content?->product_category_taxonomy_id,
            metafields: [
                [
                    'namespace' => (string) config('product-studio.shopify.metafield_namespace', 'product_studio'),
                    'key' => 'product_studio_id',
                    'type' => 'single_line_text_field',
                    'value' => (string) $product->internal_reference,
                ],
            ],
        );
    }

    /**
     * ¿Se puede sincronizar? El estado sólo puede ser DRAFT (RFC-0000).
     */
    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    /**
     * Representación enviable. Se mantiene en snake_case por comodidad de
     * auditoría; la traducción a GraphQL vive en el gateway.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_studio_id' => $this->studioId,
            'title' => $this->title,
            'description_html' => $this->descriptionHtml,
            'handle' => $this->handle,
            'status' => $this->status,
            'vendor' => $this->vendor,
            'product_type' => $this->productType,
            'tags' => $this->tags,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'category_taxonomy_id' => $this->categoryTaxonomyId,
            'variants' => $this->variants,
            'media' => $this->media,
            'metafields' => $this->metafields,
        ];
    }
}
