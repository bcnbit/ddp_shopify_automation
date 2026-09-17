<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\InventoryPolicy;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Products\ProductReadiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permite_dos_variantes_con_la_misma_combinacion(): void
    {
        $product = Product::factory()->create();

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create();

        $this->expectException(QueryException::class);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create();
    }

    public function test_la_misma_combinacion_si_se_permite_en_productos_distintos(): void
    {
        $uno = Product::factory()->create();
        $dos = Product::factory()->create();

        ProductVariant::factory()->forProduct($uno)->combination('Blanco', 'M')->create();
        ProductVariant::factory()->forProduct($dos)->combination('Blanco', 'M')->create();

        $this->assertSame(1, $uno->variants()->count());
        $this->assertSame(1, $dos->variants()->count());
    }

    public function test_el_sku_es_unico_en_todo_el_catalogo_sin_distinguir_mayusculas(): void
    {
        ProductVariant::factory()->create(['sku' => 'DDP-1001-B-M']);

        $this->expectException(QueryException::class);

        ProductVariant::factory()->create(['sku' => 'ddp 1001 b m']);
    }

    public function test_normaliza_el_sku_al_guardar(): void
    {
        $variant = ProductVariant::factory()->create(['sku' => 'ddp 3003 m blanco']);

        $this->assertSame('DDP-3003-M-BLANCO', $variant->fresh()->sku_normalized);
        $this->assertSame('ddp 3003 m blanco', $variant->fresh()->sku);
    }

    public function test_normaliza_las_opciones_vacias_para_que_la_unicidad_funcione(): void
    {
        $variant = ProductVariant::factory()->withoutOptions()->create(['sku' => 'DDP-4004']);

        $this->assertSame('', $variant->fresh()->option1_name);
        $this->assertSame('', $variant->fresh()->option2_value);
    }

    public function test_no_permite_dos_variantes_sin_opciones_en_el_mismo_producto(): void
    {
        $product = Product::factory()->create();

        ProductVariant::factory()->forProduct($product)->withoutOptions()->create(['sku' => 'DDP-5005']);

        $this->expectException(QueryException::class);

        ProductVariant::factory()->forProduct($product)->withoutOptions()->create(['sku' => 'DDP-6006']);
    }

    public function test_hereda_el_precio_del_producto_si_no_tiene_propio(): void
    {
        $product = Product::factory()->create(['price' => 30.00]);
        $variant = ProductVariant::factory()->forProduct($product)->create(['price' => null]);

        $this->assertSame('30.00', $variant->effectivePrice());
    }

    /**
     * Heredar el precio de la ficha no puede exigir cargarla aparte.
     *
     * En desarrollo la carga diferida está prohibida, así que validar una ficha
     * cuyas variantes no llevan precio propio reventaba el panel con
     * «Attempted to lazy load [product]». Se reproducía al abrir una ficha real:
     * `ProductValidator` recorre las variantes y llama a `effectivePrice()`.
     *
     * La prueba necesita **dos** variantes a propósito: Eloquent sólo propaga la
     * prohibición de carga diferida cuando la consulta hidrata más de un
     * registro (`Builder::hydrate`), así que con una sola variante el fallo no
     * se manifestaría y la prueba no protegería nada.
     */
    public function test_hereda_el_precio_de_la_ficha_sin_disparar_carga_diferida(): void
    {
        Model::preventLazyLoading();

        try {
            $product = Product::factory()->create(['price' => 30.00]);

            ProductVariant::factory()->forProduct($product)
                ->combination('Blanco', 'S')->create(['sku' => 'LD-1', 'price' => null]);
            ProductVariant::factory()->forProduct($product)
                ->combination('Blanco', 'M')->create(['sku' => 'LD-2', 'price' => null]);

            $loaded = Product::with(['variants', 'media', 'contents'])->findOrFail($product->getKey());

            $this->assertCount(2, $loaded->variants);

            foreach ($loaded->variants as $variant) {
                $this->assertSame('30.00', $variant->effectivePrice());
            }

            // Y el validador, que es quien recorría las variantes en el panel.
            $this->assertNotNull(ProductReadiness::validation($loaded));
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_prioriza_el_precio_de_la_variante(): void
    {
        $product = Product::factory()->create(['price' => 30.00]);
        $variant = ProductVariant::factory()->forProduct($product)->create(['price' => 45.00]);

        $this->assertSame('45.00', $variant->effectivePrice());
    }

    public function test_la_politica_de_inventario_por_defecto_no_vende_sin_stock(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->assertSame(InventoryPolicy::Deny, $variant->fresh()->inventory_policy);
        $this->assertNull($variant->fresh()->inventory_quantity);
    }

    public function test_muestra_una_etiqueta_legible_de_la_combinacion(): void
    {
        $variant = ProductVariant::factory()->combination('Arena', 'L')->create();

        $this->assertSame('Arena / L', $variant->optionLabel());
    }

    public function test_etiqueta_los_productos_sin_opciones(): void
    {
        $variant = ProductVariant::factory()->withoutOptions()->create();

        $this->assertSame('Sin opciones', $variant->optionLabel());
    }

    public function test_se_ordenan_por_posicion(): void
    {
        $product = Product::factory()->create();

        // Combinaciones explícitas: el factory elige color y talla al azar y dos
        // variantes del mismo producto podrían colisionar con el índice único de
        // combinación, haciendo la prueba intermitente.
        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'S')
            ->create(['position' => 2, 'sku' => 'Z-1']);
        ProductVariant::factory()->forProduct($product)->combination('Negro', 'L')
            ->create(['position' => 0, 'sku' => 'Z-2']);

        $this->assertSame(['Z-2', 'Z-1'], $product->variants()->pluck('sku')->all());
    }
}
