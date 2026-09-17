<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos ficticios para desarrollo local (RFC-0007).
 *
 * Sólo se ejecuta con `--env=local`. Nunca debe ejecutarse en producción.
 */
class DemoProductSeeder extends Seeder
{
    public function run(): void
    {
        $operadora = User::whereHas('roles', fn ($q) => $q->where('name', 'operadora'))->first()
            ?? User::factory()->operadora()->create();

        $product = Product::factory()
            ->withConfirmedFacts()
            ->inReview()
            ->create(['created_by' => $operadora->getKey()]);

        ProductContent::factory()
            ->aiGenerated()
            ->forProduct($product)
            ->create();

        ProductMedia::factory()
            ->forProduct($product)
            ->primary()
            ->withAltText()
            ->create(['sort_order' => 0]);

        $combinations = [
            ['Blanco', 'S'],
            ['Blanco', 'M'],
            ['Negro', 'M'],
            ['Negro', 'L'],
        ];

        foreach ($combinations as $index => [$color, $size]) {
            ProductVariant::factory()
                ->forProduct($product)
                ->combination($color, $size)
                ->create([
                    'sku' => $product->internal_reference.'-'.strtoupper($color[0]).'-'.$size,
                    'position' => $index,
                ]);
        }

        $this->command?->info('Ficha de demostración creada: '.$product->internal_reference);
    }
}
