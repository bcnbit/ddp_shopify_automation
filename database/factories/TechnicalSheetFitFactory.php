<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetFit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalSheetFit>
 */
class TechnicalSheetFitFactory extends Factory
{
    protected $model = TechnicalSheetFit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'FIT-'.fake()->unique()->numberBetween(100, 9999),
            'name' => fake()->randomElement([
                'Unisex regular',
                'Mujer entallada',
                'Oversize',
                'Corte recto',
            ]),
            'product_type' => null,
            'audience' => null,
            'is_active' => true,
            'content_text' => fake()->randomElement([
                'Corte regular, unisex, con caída natural.',
                'Corte entallado que marca la silueta sin apretar.',
                'Corte amplio y holgado, hombro caído.',
                'Corte recto, sin entallar, largo estándar.',
            ]),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function forType(ProductType $type): static
    {
        return $this->state(fn (array $attributes): array => ['product_type' => $type]);
    }

    public function forAudience(Audience $audience): static
    {
        return $this->state(fn (array $attributes): array => ['audience' => $audience]);
    }
}
