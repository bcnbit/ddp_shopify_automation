<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\Locale;
use App\Models\Product;
use App\Models\ProductContent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductContentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitiza_el_html_al_guardar(): void
    {
        $content = ProductContent::factory()->create([
            'html_description' => '<p onclick="x()">Texto <strong>válido</strong><script>alert(1)</script></p>',
        ]);

        $stored = $content->fresh()->html_description;

        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<strong>válido</strong>', $stored);
    }

    public function test_elimina_etiquetas_no_permitidas_al_guardar(): void
    {
        $content = ProductContent::factory()->create([
            'html_description' => '<h1>Título grande</h1><p>Párrafo</p>',
        ]);

        $this->assertStringNotContainsString('<h1>', $content->fresh()->html_description);
    }

    public function test_no_permite_dos_versiones_del_mismo_idioma(): void
    {
        $product = Product::factory()->create();
        ProductContent::factory()->forProduct($product)->version(1)->create();

        $this->expectException(QueryException::class);

        ProductContent::factory()->forProduct($product)->version(1)->create();
    }

    public function test_permite_varios_idiomas_y_versiones(): void
    {
        $product = Product::factory()->create();

        ProductContent::factory()->forProduct($product)->create(['locale' => Locale::Es, 'version' => 1]);
        ProductContent::factory()->forProduct($product)->create(['locale' => Locale::Es, 'version' => 2]);
        ProductContent::factory()->forProduct($product)->create(['locale' => Locale::Ca, 'version' => 1]);

        $this->assertSame(3, $product->contents()->count());
    }

    public function test_convierte_el_idioma_a_enum(): void
    {
        $content = ProductContent::factory()->create(['locale' => Locale::Es]);

        $this->assertInstanceOf(Locale::class, $content->fresh()->locale);
    }

    public function test_devuelve_las_etiquetas_como_lista(): void
    {
        $content = ProductContent::factory()->create([
            'tags_json' => ['camiseta', 'algodón', '', 'verano'],
        ]);

        $this->assertSame(['camiseta', 'algodón', 'verano'], $content->fresh()->tags());
    }

    public function test_devuelve_las_advertencias_de_datos_no_confirmados(): void
    {
        $content = ProductContent::factory()->withWarnings()->create();

        $this->assertNotEmpty($content->fresh()->warnings());
        $this->assertStringContainsString('Composición', $content->fresh()->warnings()[0]);
    }

    public function test_distingue_una_propuesta_generada_por_ia(): void
    {
        $this->assertTrue(ProductContent::factory()->aiGenerated()->create()->isAiGenerated());
        $this->assertFalse(ProductContent::factory()->create()->isAiGenerated());
    }

    public function test_una_version_aprobada_se_reconoce_como_tal(): void
    {
        $content = ProductContent::factory()->approved()->create();

        $this->assertTrue($content->isApproved());
    }

    public function test_el_cascade_elimina_el_contenido_con_el_producto(): void
    {
        $product = Product::factory()->create();
        ProductContent::factory()->forProduct($product)->create();

        $product->delete();

        $this->assertSame(0, ProductContent::count());
    }
}
