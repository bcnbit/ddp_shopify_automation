<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetComposition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalSheetComposition>
 */
class TechnicalSheetCompositionFactory extends Factory
{
    protected $model = TechnicalSheetComposition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'COMP-'.fake()->unique()->numberBetween(100, 9999),
            'name' => fake()->randomElement([
                'Algodón 100%',
                'Algodón y poliéster',
                'Lino y viscosa',
                'Rafia natural',
            ]),
            'product_type' => null,
            'audience' => null,
            'is_active' => true,
            'content_text' => fake()->randomElement([
                '100% algodón',
                '80% algodón / 20% poliéster',
                '55% lino / 45% viscosa',
                '100% rafia natural',
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
