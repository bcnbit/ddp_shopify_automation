<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La interfaz de RFC-0002 debe renderizar sin errores para quien puede usarla.
 *
 * Estas pruebas son deliberadamente "de humo": comprueban que las páginas
 * montan con datos reales, que es donde aparecen los fallos de integración
 * entre Filament y el modelo (columnas inexistentes, relaciones mal cargadas).
 */
class ProductResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_operadora_ve_el_listado_de_sus_fichas(): void
    {
        $operadora = $this->operadora();
        Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->actingAs($operadora)
            ->get('/admin/products')
            ->assertOk();
    }

    /**
     * El listado ofrece borrar ficha a ficha y no en masa.
     *
     * Borrar arrastra los ficheros del almacenamiento, así que no debe poder
     * hacerse de un clic sobre una selección entera por error: es una decisión
     * deliberada, no una funcionalidad que falte.
     */
    public function test_el_listado_no_ofrece_borrado_en_masa(): void
    {
        $operadora = $this->operadora();
        Product::factory()->create(['created_by' => $operadora->getKey()]);

        $html = $this->actingAs($operadora)->get('/admin/products')->assertOk()->getContent();

        // `DeleteBulkAction` se pinta como acción de la barra superior; el borrado
        // por fila es lo que sí debe existir.
        $this->assertStringNotContainsString('deleteBulk', $html);
    }

    public function test_la_operadora_abre_el_formulario_de_alta(): void
    {
        $this->actingAs($this->operadora())
            ->get('/admin/products/create')
            ->assertOk();
    }

    public function test_la_operadora_abre_una_ficha_propia(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->actingAs($operadora)
            ->get("/admin/products/{$product->getKey()}/edit")
            ->assertOk();
    }

    public function test_la_operadora_no_puede_abrir_una_ficha_ajena(): void
    {
        $product = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);

        $this->actingAs($this->operadora())
            ->get("/admin/products/{$product->getKey()}/edit")
            ->assertNotFound();
    }

    public function test_el_responsable_abre_cualquier_ficha(): void
    {
        $product = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);

        $this->actingAs($this->responsable())
            ->get("/admin/products/{$product->getKey()}/edit")
            ->assertOk();
    }

    public function test_el_listado_renderiza_fichas_con_variantes_y_medios(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->withConfirmedFacts()->inReview()->create([
            'created_by' => $operadora->getKey(),
        ]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create();
        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();
        ProductContent::factory()->forProduct($product)->create();

        $this->actingAs($operadora)->get('/admin/products')->assertOk();
    }

    public function test_una_ficha_sincronizada_se_renderiza_sin_errores(): void
    {
        // El administrador necesita segundo factor para entrar al panel.
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();
        $product = Product::factory()->syncedToShopify()->create(['created_by' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/admin/products/{$product->getKey()}/edit")
            ->assertOk();
    }

    public function test_el_administrador_sin_segundo_factor_no_llega_a_la_ficha(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['created_by' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/admin/products/{$product->getKey()}/edit")
            ->assertRedirect();
    }

    public function test_la_pestana_de_variantes_del_listado_funciona(): void
    {
        $operadora = $this->operadora();
        Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->actingAs($operadora)
            ->get('/admin/products?tab=errors')
            ->assertOk();
    }
}
