<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use App\Support\Media\MediaRules;

/**
 * Valida una ficha antes de enviarla a Shopify (RFC-0002 y RFC-0005).
 *
 * Distingue dos severidades, porque tienen consecuencias distintas:
 *
 * - **Bloqueante**: impide sincronizar. Son datos comerciales que una persona
 *   debe aportar (precio, SKU, foto principal, título, descripción, handle).
 * - **Aviso**: no impide enviar. La ficha queda señalada para que la persona
 *   decida, por ejemplo una meta description algo corta.
 *
 * Este validador no consulta Shopify ni la IA: sólo mira datos locales.
 */
final class ProductValidator
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    public function validate(Product $product): ValidationResult
    {
        $this->issues = [];

        $this->checkPrice($product);
        $this->checkReference($product);
        $this->checkVariants($product);
        $this->checkPrimaryMedia($product);
        $this->checkMediaAltTexts($product);
        $this->checkContent($product);

        return new ValidationResult($this->issues);
    }

    private function checkPrice(Product $product): void
    {
        $price = $product->price;

        if ($price === null || (float) $price <= 0) {
            $this->block(
                'price',
                'Falta el precio de venta o no es un importe positivo.',
                'Precio ausente o no positivo',
            );
        }
    }

    private function checkReference(Product $product): void
    {
        if (blank($product->internal_reference)) {
            $this->block('internal_reference', 'La referencia interna es obligatoria.', 'Referencia interna ausente');
        }

        if (blank($product->source_name)) {
            $this->block('source_name', 'El nombre provisional es obligatorio.', 'Nombre provisional ausente');
        }

        if ($product->product_type === null) {
            $this->block('product_type', 'Indica el tipo de prenda.', 'Tipo de prenda ausente');
        }
    }

    private function checkVariants(Product $product): void
    {
        $variants = $product->variants;

        if ($variants->isEmpty()) {
            $this->block(
                'variants',
                'La ficha necesita al menos una variante vendible o confirmar que el producto no tiene variantes.',
                'Sin variantes vendibles',
            );

            return;
        }

        $seenSkus = [];

        foreach ($variants as $variant) {
            $position = $variant->position + 1;

            if (blank($variant->sku)) {
                $this->block('variants', "La variante {$position} no tiene SKU.", 'SKU vacío');
            } else {
                $normalized = SkuNormalizer::normalize($variant->sku);

                if ($normalized !== null && in_array($normalized, $seenSkus, true)) {
                    $this->block('variants', "El SKU {$variant->sku} está repetido dentro de la ficha.", 'SKU duplicado');
                }

                if ($normalized !== null) {
                    $seenSkus[] = $normalized;
                }
            }

            if ($variant->effectivePrice() === null) {
                $this->block(
                    'variants',
                    "La variante {$position} no hereda ni define un precio.",
                    'Variante sin precio',
                );
            }

            if ($variant->inventory_quantity !== null && $variant->inventory_quantity < 0) {
                $this->block('variants', "La variante {$position} tiene una cantidad negativa.", 'Cantidad inválida');
            }
        }
    }

    private function checkPrimaryMedia(Product $product): void
    {
        $media = $product->media;

        if ($media->isEmpty()) {
            $this->block('media', 'La ficha necesita al menos una foto.', 'Sin foto principal');

            return;
        }

        if (! $media->contains(static fn ($item): bool => (bool) $item->is_primary)) {
            $this->block('media', 'Marca una foto como principal.', 'Sin foto principal');
        }
    }

    private function checkMediaAltTexts(Product $product): void
    {
        foreach ($product->media as $item) {
            if (blank($item->alt_text)) {
                $this->warn(
                    'media',
                    "La imagen «{$item->original_filename}» no tiene ALT aprobado.",
                    'ALT pendiente',
                );
            }

            if ($item->width !== null && $item->height !== null) {
                if ($item->width < MediaRules::minWidth() || $item->height < MediaRules::minHeight()) {
                    $this->warn(
                        'media',
                        "La imagen «{$item->original_filename}» tiene baja resolución ({$item->width}×{$item->height}).",
                        'Imagen de baja resolución',
                    );
                }
            }
        }
    }

    private function checkContent(Product $product): void
    {
        // Un borrador recién creado no tiene contenido todavía: eso es normal y
        // sólo importa cuando la ficha se va a enviar, no mientras se prepara.
        $content = $product->contentFor();

        if ($content === null) {
            if ($product->status->isSynced()) {
                $this->block('content', 'La ficha no tiene contenido comercial.', 'Sin título ni descripción');

                return;
            }

            $this->warn('content', 'Todavía no hay ninguna propuesta de contenido para esta ficha.', 'Contenido pendiente');

            return;
        }

        if (blank($content->title)) {
            $this->block('content', 'Falta el título del producto.', 'Sin título');
        }

        if (blank($content->html_description)) {
            $this->block('content', 'Falta la descripción del producto.', 'Sin descripción');
        }

        if (blank($content->handle)) {
            $this->block('content', 'Falta el handle de Shopify.', 'Handle ausente');
        }

        $this->checkSeoRanges($content->seo_title, $content->seo_description, $content->tags());

        foreach ($content->warnings() as $warning) {
            $this->warn('content', $warning, 'Dato no confirmado');
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function checkSeoRanges(?string $seoTitle, ?string $seoDescription, array $tags): void
    {
        $titleMin = (int) config('product-studio.content.seo_title_min', 50);
        $titleMax = (int) config('product-studio.content.seo_title_max', 60);
        $descriptionMin = (int) config('product-studio.content.seo_description_min', 140);
        $descriptionMax = (int) config('product-studio.content.seo_description_max', 160);
        $tagsMin = (int) config('product-studio.content.tags_min', 5);
        $tagsMax = (int) config('product-studio.content.tags_max', 12);

        if (filled($seoTitle)) {
            $length = mb_strlen($seoTitle);

            if ($length < $titleMin || $length > $titleMax) {
                $this->warn(
                    'content',
                    "El meta title tiene {$length} caracteres (objetivo {$titleMin}-{$titleMax}).",
                    'Título demasiado largo o corto',
                );
            }
        }

        if (filled($seoDescription)) {
            $length = mb_strlen($seoDescription);

            if ($length < $descriptionMin || $length > $descriptionMax) {
                $this->warn(
                    'content',
                    "La meta description tiene {$length} caracteres (objetivo {$descriptionMin}-{$descriptionMax}).",
                    'Meta description fuera de rango',
                );
            }
        }

        $tagCount = count($tags);

        if ($tagCount > 0 && ($tagCount < $tagsMin || $tagCount > $tagsMax)) {
            $this->warn(
                'content',
                "Hay {$tagCount} etiquetas (objetivo {$tagsMin}-{$tagsMax}).",
                'Etiquetas fuera de rango',
            );
        }
    }

    private function block(string $field, string $message, string $code): void
    {
        $this->issues[] = ValidationIssue::blocking($field, $message, $code);
    }

    private function warn(string $field, string $message, string $code): void
    {
        $this->issues[] = ValidationIssue::warning($field, $message, $code);
    }
}
