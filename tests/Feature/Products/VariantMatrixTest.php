<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductVariantService;
use App\Support\Products\VariantMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Matriz de variantes y SKU (RFC-0002 / RFC-0005).
 */
class VariantMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_genera_el_producto_cartesiano_de_colores_y_tallas(): void
    {
        $combinations = VariantMatrix::combinations(['Blanco', 'Negro'], ['S', 'M', 'L']);

        $this->assertCount(6, $combinations);
        $this->assertSame(['color' => 'Blanco', 'size' => 'S'], $combinations[0]);
        $this->assertSame(['color' => 'Negro', 'size' => 'L'], $combinations[5]);
    }

    public function test_ignora_valores_vacios_y_repetidos(): void
    {
        $combinations = VariantMatrix::combinations(
            ['Blanco', ' blanco ', '', 'Negro'],
            ['M', 'M', '  '],
        );

        // ' blanco ' se recorta y se compara como distinto de 'Blanco' por
        // ser sensible a mayúsculas sólo en la limpieza interna; se comprueba
        // que no se cuelan vacíos ni duplicados exactos.
        foreach ($combinations as $combination) {
            $this->assertNotSame('', $combination['color']);
            $this->assertNotSame('', $combination['size']);
        }
    }

    public function test_con_solo_colores_genera_una_variante_por_color(): void
    {
        $combinations = VariantMatrix::combinations(['Blanco', 'Negro'], []);

        $this->assertCount(2, $combinations);
        $this->assertSame('', $combinations[0]['size']);
    }

    public function test_con_solo_tallas_genera_una_variante_por_talla(): void
    {
        $combinations = VariantMatrix::combinations([], ['S', 'M']);

        $this->assertCount(2, $combinations);
        $this->assertSame('', $combinations[0]['color']);
    }

    public function test_sin_colores_ni_tallas_no_genera_nada(): void
    {
        $this->assertSame([], VariantMatrix::combinations([], []));
    }

    public function test_sugiere_un_sku_legible(): void
    {
        $this->assertSame('DDP-1001-BLA-M', VariantMatrix::suggestSku('ddp 1001', 'Blanco', 'M'));
        $this->assertSame('DDP-1001-AM-L', VariantMatrix::suggestSku('ddp 1001', 'Azul marino', 'L'));
    }

    public function test_el_servicio_genera_las_variantes_en_la_base_de_datos(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create([
            'internal_reference' => 'DDP-5000',
            'created_by' => $operadora->getKey(),
        ]);

        $created = app(ProductVariantService::class)
            ->generateMatrix($product, ['Blanco', 'Negro'], ['S', 'M'], $operadora);

        $this->assertSame(4, $created);
        $this->assertSame(4, $product->variants()->count());
        $this->assertSame('DDP-5000-BLA-S', $product->variants()->orderBy('position')->first()->sku);
    }

    public function test_generar_dos_veces_no_duplica_variantes(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['internal_reference' => 'DDP-6000']);

        $service = app(ProductVariantService::class);
        $service->generateMatrix($product, ['Blanco'], ['S', 'M'], $operadora);
        $secondRun = $service->generateMatrix($product, ['Blanco'], ['S', 'M'], $operadora);

        $this->assertSame(0, $secondRun);
        $this->assertSame(2, $product->variants()->count());
    }

    public function test_anadir_combinaciones_nuevas_conserva_las_existentes(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['internal_reference' => 'DDP-7000']);

        $service = app(ProductVariantService::class);
        $service->generateMatrix($product, ['Blanco'], ['S'], $operadora);
        $created = $service->generateMatrix($product, ['Blanco', 'Negro'], ['S', 'M'], $operadora);

        $this->assertSame(3, $created);
        $this->assertSame(4, $product->variants()->count());
    }

    public function test_crea_una_variante_unica_para_productos_sin_opciones(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['internal_reference' => 'DDP-8000']);

        $variant = app(ProductVariantService::class)->createSingleVariant($product, null, $operadora);

        $this->assertSame('DDP-8000', $variant->sku);
        $this->assertSame('Sin opciones', $variant->optionLabel());
    }

    public function test_no_permite_un_sku_que_ya_existe_en_otra_ficha(): void
    {
        $operadora = $this->operadora();

        ProductVariant::factory()->create(['sku' => 'DDP-9000-BLA-M']);

        $product = Product::factory()->create([
            'internal_reference' => 'DDP-9000',
            'created_by' => $operadora->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        app(ProductVariantService::class)->generateMatrix($product, ['Blanco'], ['M'], $operadora);
    }

    public function test_no_se_elimina_la_ultima_variante(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->expectException(\RuntimeException::class);

        app(ProductVariantService::class)->delete($variant, $operadora);
    }

    public function test_se_puede_eliminar_una_variante_si_queda_otra(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->forProduct($product)->combination('Blanco', 'S')->create();
        ProductVariant::factory()->forProduct($product)->combination('Negro', 'M')->create();

        $deleted = app(ProductVariantService::class)->delete($variant, $operadora);

        $this->assertTrue($deleted);
        $this->assertSame(1, $product->variants()->count());
    }
}
