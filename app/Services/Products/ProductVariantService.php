<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\ActivityEvent;
use App\Enums\InventoryPolicy;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Audit\ActivityRecorder;
use App\Support\Products\SkuNormalizer;
use App\Support\Products\VariantMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Gestión de la matriz color × talla (RFC-0002 / RFC-0005).
 *
 * La tabla local es la fuente de verdad. Generar la matriz crea sólo las
 * combinaciones que faltan y nunca borra variantes existentes: eliminar una
 * variante es siempre una acción explícita de la persona.
 */
class ProductVariantService
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * @param  list<string>  $colors
     * @param  list<string>  $sizes
     * @return int número de variantes creadas
     */
    public function generateMatrix(Product $product, array $colors, array $sizes, User $author): int
    {
        $combinations = VariantMatrix::combinations($colors, $sizes);

        if ($combinations === []) {
            return 0;
        }

        return DB::transaction(function () use ($product, $combinations, $author): int {
            $existing = $product->variants()->get()->map(
                static fn (ProductVariant $variant): string => self::combinationKey(
                    $variant->option1_value,
                    $variant->option2_value,
                ),
            )->all();

            $position = (int) $product->variants()->max('position');
            $created = 0;

            foreach ($combinations as $combination) {
                $key = self::combinationKey($combination['color'], $combination['size']);

                if (in_array($key, $existing, true)) {
                    continue;
                }

                $position++;

                $variant = new ProductVariant([
                    'sku' => VariantMatrix::suggestSku(
                        (string) $product->internal_reference,
                        $combination['color'],
                        $combination['size'],
                    ),
                    'option1_name' => $combination['color'] === '' ? '' : 'Color',
                    'option1_value' => $combination['color'],
                    'option2_name' => $combination['size'] === '' ? '' : 'Talla',
                    'option2_value' => $combination['size'],
                    'inventory_policy' => (string) config(
                        'product-studio.variants.default_inventory_policy',
                        InventoryPolicy::Deny->value,
                    ),
                    'position' => $position,
                ]);

                $variant->product_id = $product->getKey();
                $this->guardSkuIsAvailable($variant);
                $variant->save();

                $existing[] = $key;
                $created++;
            }

            if ($created > 0) {
                $this->recorder->record(
                    ActivityEvent::Updated,
                    $product,
                    "Se han generado {$created} variantes a partir de la matriz color × talla.",
                    ['created' => $created],
                    actor: $author,
                );
            }

            return $created;
        });
    }

    /**
     * Variante sin opciones: producto único y vendible (RFC-0002 permite
     * confirmar explícitamente que el producto no tiene variantes).
     */
    public function createSingleVariant(Product $product, ?string $sku, User $author): ProductVariant
    {
        return DB::transaction(function () use ($product, $sku, $author): ProductVariant {
            $variant = new ProductVariant([
                'sku' => $sku ?: SkuNormalizer::normalize((string) $product->internal_reference),
                'option1_name' => '',
                'option1_value' => '',
                'option2_name' => '',
                'option2_value' => '',
                'inventory_policy' => (string) config(
                    'product-studio.variants.default_inventory_policy',
                    InventoryPolicy::Deny->value,
                ),
                'position' => (int) $product->variants()->max('position') + 1,
            ]);

            $variant->product_id = $product->getKey();
            $this->guardSkuIsAvailable($variant);
            $variant->save();

            $this->recorder->record(
                ActivityEvent::Updated,
                $product,
                'Variante única añadida.',
                ['variant_id' => $variant->getKey()],
                actor: $author,
            );

            return $variant;
        });
    }

    public function delete(ProductVariant $variant, User $author): bool
    {
        $product = $variant->product;

        if ($product->variants()->count() <= 1) {
            throw new RuntimeException('No se puede eliminar la última variante vendible de la ficha.');
        }

        return DB::transaction(function () use ($variant, $product, $author): bool {
            $variantId = $variant->getKey();
            $variant->delete();

            $this->recorder->record(
                ActivityEvent::Updated,
                $product,
                'Variante eliminada.',
                ['variant_id' => $variantId],
                actor: $author,
            );

            return true;
        });
    }

    /**
     * El SKU debe ser único en todo el catálogo gestionado (RFC-0005).
     */
    private function guardSkuIsAvailable(ProductVariant $variant): void
    {
        $normalized = SkuNormalizer::normalize($variant->sku);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'variants' => 'Cada variante necesita un SKU.',
            ]);
        }

        $exists = ProductVariant::query()
            ->where('sku_normalized', $normalized)
            ->when($variant->exists, fn ($query) => $query->whereKeyNot($variant->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'variants' => "El SKU «{$variant->sku}» ya está en uso en el catálogo.",
            ]);
        }
    }

    private static function combinationKey(string $color, string $size): string
    {
        return mb_strtolower(trim($color)).'|'.mb_strtolower(trim($size));
    }
}
