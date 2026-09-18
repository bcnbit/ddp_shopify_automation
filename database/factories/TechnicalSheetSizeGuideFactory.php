<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetSizeGuide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalSheetSizeGuide>
 */
class TechnicalSheetSizeGuideFactory extends Factory
{
    protected $model = TechnicalSheetSizeGuide::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TALLA-'.fake()->unique()->numberBetween(100, 9999),
            'name' => fake()->randomElement([
                'Guía de tallas camiseta adulto',
                'Guía de tallas sudadera',
                'Guía de tallas infantil',
            ]),
            'product_type' => null,
            'audience' => null,
            'is_active' => true,
            'intro_note' => 'Medidas tomadas en plano, en centímetros. Puede haber una variación de ±1 cm.',
            'content_html' => self::table(),
            'closing_note' => 'Si dudas entre dos tallas, elige la mayor para un ajuste holgado.',
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

    /**
     * Tabla de ejemplo, con la forma que la whitelist de RFC-0008 permite.
     */
    public static function table(): string
    {
        return '<table>'
            .'<thead><tr><th>Talla</th><th>Pecho (cm)</th><th>Largo (cm)</th></tr></thead>'
            .'<tbody>'
            .'<tr><td>S</td><td>96</td><td>68</td></tr>'
            .'<tr><td>M</td><td>102</td><td>70</td></tr>'
            .'<tr><td>L</td><td>108</td><td>72</td></tr>'
            .'<tr><td>XL</td><td>114</td><td>74</td></tr>'
            .'</tbody>'
            .'</table>';
    }
}
