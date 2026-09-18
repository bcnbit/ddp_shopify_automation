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
 *
 * `generateSizes()` es el atajo de un solo eje (tallas), para el botón «Añadir
 * Variante Tallas» de la ficha. Comparte con `generateMatrix()` la regla que más
 * importa: **crear sólo lo que falta**, nunca duplicar ni pisar una variante que
 * ya exista.
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

            // La posición la asigna el modelo al crear (ProductVariant::booted),
            // igual que en `generateSizes()`: una sola regla para las dos vías.
            $created = 0;

            foreach ($combinations as $combination) {
                $key = self::combinationKey($combination['color'], $combination['size']);

                if (in_array($key, $existing, true)) {
                    continue;
                }

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
     * Crea las tallas estándar que falten, en un solo paso.
     *
     * Es el botón «Añadir Variante Tallas» de la ficha. A diferencia de
     * `generateMatrix()`, aquí no hay eje de color: las tallas se crean con el
     * color **vacío**, que es como están hoy las variantes de talla de la tienda
     * (decisión confirmada al definir la función).
     *
     * Dos decisiones que conviene no revertir sin querer:
     *
     * - **Copia el precio de la ficha**, no lo deja vacío. Una variante con el
     *   precio copiado es la que espera quien pulsa el botón: el número queda a la
     *   vista en el listado. La contrapartida es que cambiar después el precio de la
     *   ficha no arrastra a estas variantes; en el panel se ven y se editan una a una.
     * - **Es idempotente**: si una talla ya existe para esta ficha, no se toca. Así
     *   pulsar el botón dos veces no falla por SKU duplicado, y se puede completar
     *   una ficha que sólo tenía algunas tallas.
     *
     * No toca las imágenes: la foto destacada pertenece a la ficha y las variantes
     * no tienen imagen propia en el modelo actual.
     *
     * @return int número de variantes creadas
     */
    public function generateSizes(Product $product, User $author): int
    {
        /** @var list<string> $sizes */
        $sizes = array_values((array) config('product-studio.variants.standard_sizes', []));

        if ($sizes === []) {
            return 0;
        }

        return DB::transaction(function () use ($product, $sizes, $author): int {
            // Se comparan las tallas ya presentes por su SKU completo, que es lo
            // único que distingue una talla repetida dentro de la ficha: la talla
            // suelta («M») también podría aparecer en otra opción.
            $existing = $product->variants()->get()->map(
                static fn (ProductVariant $variant): string => self::combinationKey(
                    $variant->option1_value,
                    $variant->option2_value,
                ),
            )->all();

            // La posición la asigna el modelo al crear: así hay una sola regla y
            // no puede divergir entre este servicio y el alta manual.
            $reference = (string) $product->internal_reference;
            $price = $product->price;
            $created = 0;

            foreach ($sizes as $size) {
                $key = self::combinationKey('', $size);

                if (in_array($key, $existing, true)) {
                    continue;
                }

                $variant = new ProductVariant([
                    // El SKU se compone con la referencia y la talla, sin color:
                    // `DDP-SS-VOICE-S`. Es el formato pedido y el que ya existe.
                    'sku' => self::sizeSku($reference, $size),
                    // Color declarado pero vacío: es la forma en que la tienda
                    // representa «esta ficha no varía por color».
                    'option1_name' => 'Color',
                    'option1_value' => '',
                    'option2_name' => 'Talla',
                    'option2_value' => $size,
                    'price' => $price,
                    'inventory_policy' => (string) config(
                        'product-studio.variants.default_inventory_policy',
                        InventoryPolicy::Deny->value,
                    ),
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
                    "Se han añadido {$created} variantes de talla.",
                    ['created' => $created, 'sizes' => $sizes],
                    actor: $author,
                );
            }

            return $created;
        });
    }

    /**
     * SKU de una variante de talla: referencia + talla, sin color.
     *
     * Se normaliza la referencia igual que en el resto del catálogo para que un
     * espacio o un guion bajo no produzcan un SKU distinto del esperado.
     */
    private static function sizeSku(string $reference, string $size): string
    {
        $parts = array_filter([
            SkuNormalizer::normalize($reference),
            mb_strtoupper(trim($size)),
        ]);

        return implode('-', $parts);
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

    /**
     * Clave con la que se decide si una combinación ya existe.
     *
     * Es **pública** porque la interfaz necesita hacer la misma pregunta para
     * avisar de cuántas tallas se van a añadir: si cada una contara a su manera, el
     * aviso y lo que de verdad ocurre podrían no coincidir.
     */
    public static function combinationKey(string $color, string $size): string
    {
        return mb_strtolower(trim($color)).'|'.mb_strtolower(trim($size));
    }
}
