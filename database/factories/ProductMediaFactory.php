<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaUploadStatus;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductMedia>
 */
class ProductMediaFactory extends Factory
{
    protected $model = ProductMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = Str::uuid()->toString().'.jpg';

        return [
            'product_id' => Product::factory(),
            'disk' => 'media',
            'path' => 'products/originals/'.$filename,
            'original_filename' => $filename,
            'mime_type' => 'image/jpeg',
            'bytes' => fake()->numberBetween(120_000, 4_000_000),
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'width' => fake()->numberBetween(1200, 4000),
            'height' => fake()->numberBetween(1200, 4000),
            'alt_text' => null,
            'is_primary' => false,
            'sort_order' => 0,
            'shopify_media_gid' => null,
            'upload_status' => MediaUploadStatus::Pending,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }

    public function withAltText(?string $alt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'alt_text' => $alt ?? 'Camiseta de algodón Dies de Platja vista de frente',
        ]);
    }

    public function lowResolution(): static
    {
        return $this->state(fn (array $attributes): array => [
            'width' => 320,
            'height' => 320,
        ]);
    }

    public function uploaded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'upload_status' => MediaUploadStatus::Uploaded,
            'shopify_media_gid' => 'gid://shopify/MediaImage/'.fake()->unique()->numberBetween(100000, 999999),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $product->getKey(),
        ]);
    }
}
