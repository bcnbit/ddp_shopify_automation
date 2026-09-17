<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Product;
use App\Models\ProductContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductContent>
 */
class ProductContentFactory extends Factory
{
    protected $model = ProductContent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'locale' => Locale::Es,
            'version' => 1,
            'title' => 'Camiseta Dies de Platja de algodón',
            'short_benefit' => 'Una camiseta suave para el día a día junto al mar.',
            'handle' => fake()->unique()->slug(4),
            'html_description' => '<p>Camiseta de corte regular con un tacto suave y un diseño inspirado en la costa.</p><ul><li>Tejido agradable al contacto con la piel</li><li>Ideal para llevar en verano</li></ul>',
            'seo_title' => 'Camiseta Dies de Platja de algodón para el día a día',
            'seo_description' => 'Camiseta Dies de Platja de corte regular y tacto suave, pensada para el día a día junto al mar. Descubre la colección de temporada en nuestra tienda.',
            'tags_json' => ['camiseta', 'algodón', 'verano', 'Dies de Platja', 'costa'],
            'alt_texts_json' => [],
            'facts_detected_json' => [],
            'warnings_json' => [],
            'product_category_taxonomy_id' => null,
            'ai_model' => null,
            'prompt_version' => null,
            'generated_at' => null,
            'approved_at' => null,
            'approved_by' => null,
        ];
    }

    public function aiGenerated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ai_model' => 'gpt-4.1',
            'prompt_version' => 'v1',
            'generated_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approved_at' => now(),
        ]);
    }

    public function withWarnings(?array $warnings = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'warnings_json' => $warnings ?? ['Composición no confirmada por una persona.'],
        ]);
    }

    public function version(int $version): static
    {
        return $this->state(fn (array $attributes): array => ['version' => $version]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $product->getKey(),
        ]);
    }
}
