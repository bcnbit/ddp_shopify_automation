<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryPolicy;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Products\SkuNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @var list<string> */
    private const COLORS = ['Blanco', 'Negro', 'Arena', 'Azul marino', 'Rojo'];

    /** @var list<string> */
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seed = fake()->unique()->numberBetween(1000, 999999);
        $sku = 'VAR-'.$seed;

        return [
            'product_id' => Product::factory(),
            'sku' => $sku,
            'sku_normalized' => SkuNormalizer::normalize($sku),
            'barcode' => null,
            // Valores derivados del SKU, no aleatorios: así dos variantes del
            // mismo producto nunca comparten combinación por azar y chocan con
            // el índice único `product_variants_combination_unique`.
            'option1_name' => 'Color',
            'option1_value' => self::COLORS[$seed % count(self::COLORS)],
            'option2_name' => 'Talla',
            'option2_value' => self::SIZES[$seed % count(self::SIZES)],
            'price' => null,
            'compare_at_price' => null,
            'inventory_policy' => InventoryPolicy::Deny,
            'inventory_quantity' => null,
            'shopify_variant_gid' => null,
            'position' => 0,
        ];
    }

    /**
     * Variante concreta: color × talla.
     */
    public function combination(string $color, string $size): static
    {
        return $this->state(fn (array $attributes): array => [
            'option1_name' => 'Color',
            'option1_value' => $color,
            'option2_name' => 'Talla',
            'option2_value' => $size,
        ]);
    }

    public function withoutOptions(): static
    {
        return $this->state(fn (array $attributes): array => [
            'option1_name' => '',
            'option1_value' => '',
            'option2_name' => '',
            'option2_value' => '',
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $product->getKey(),
        ]);
    }
}
