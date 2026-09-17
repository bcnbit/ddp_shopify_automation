<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\SkuNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = 'DDP-'.fake()->unique()->numberBetween(1000, 99999);

        return [
            'status' => ProductStatus::Draft,
            'internal_reference' => SkuNormalizer::normalize($reference),
            'source_name' => fake()->randomElement([
                'Camiseta Dies de Platja',
                'Sudadera Costa Blanca',
                'Bolso de rafia Medes',
                'Camiseta infantil Marina',
            ]),
            'brand' => 'Dies de Platja',
            'product_type' => fake()->randomElement(ProductType::cases()),
            'audience' => fake()->randomElement(Audience::cases()),
            'price' => fake()->randomFloat(2, 12, 89),
            'compare_at_price' => null,
            'currency' => 'EUR',
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
            'collection_context' => null,
            'notes' => null,
            'shopify_product_gid' => null,
            'shopify_handle' => null,
            'last_synced_at' => null,
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Product $product): void {
            $product->internal_reference = SkuNormalizer::normalize($product->internal_reference);
        });
    }

    public function status(ProductStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function draft(): static
    {
        return $this->status(ProductStatus::Draft);
    }

    public function inReview(): static
    {
        return $this->status(ProductStatus::Review);
    }

    public function approved(): static
    {
        return $this->status(ProductStatus::Approved)->state(fn (array $attributes): array => [
            'approved_at' => now(),
            'approved_by' => $attributes['created_by'] ?? User::factory(),
        ]);
    }

    public function syncedToShopify(): static
    {
        return $this->status(ProductStatus::ShopifyDraft)->state(fn (array $attributes): array => [
            'shopify_product_gid' => 'gid://shopify/Product/'.fake()->unique()->numberBetween(1000000, 9999999),
            'shopify_handle' => fake()->unique()->slug(3),
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Ficha con los datos comerciales confirmados por una persona.
     */
    public function withConfirmedFacts(): static
    {
        return $this->state(fn (array $attributes): array => [
            'composition' => '100% algodón peinado',
            'fit' => 'Corte regular',
            'care_instructions' => 'Lavar a 30 ºC del revés',
            'collection_context' => 'Verano 2026',
        ]);
    }
}
