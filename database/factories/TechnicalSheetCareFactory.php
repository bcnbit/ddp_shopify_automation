<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetCare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalSheetCare>
 */
class TechnicalSheetCareFactory extends Factory
{
    protected $model = TechnicalSheetCare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CARE-'.fake()->unique()->numberBetween(100, 9999),
            'name' => fake()->randomElement([
                'Cuidados básicos algodón',
                'Cuidados prenda estampada',
                'Cuidados rafia',
            ]),
            'product_type' => null,
            'audience' => null,
            'is_active' => true,
            'content_text' => fake()->randomElement([
                "Lavar del revés a un máximo de 30 ºC.\nNo usar secadora.\nPlanchar del revés y sin pasar sobre el estampado.",
                "Lavar a mano con agua fría.\nNo usar lejía.\nSecar a la sombra.",
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
