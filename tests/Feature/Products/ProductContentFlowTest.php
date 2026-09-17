<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Services\Products\ProductContentService;
use App\Services\Products\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Versiones de contenido y ciclo de aprobación (RFC-0002).
 */
class ProductContentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProductContentService
    {
        return app(ProductContentService::class);
    }

    public function test_la_propuesta_local_no_inventa_datos_comerciales(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create([
            'source_name' => 'Camiseta Marina',
            'composition' => null,
            'care_instructions' => null,
            'created_by' => $operadora->getKey(),
        ]);

        $content = $this->service()->createLocalDraft($product, $operadora);

        // La descripción no se rellena: RFC-0003 prohíbe afirmar composición,
        // cuidados o medidas que nadie ha confirmado todavía.
        $this->assertNull($content->html_description);
        $this->assertSame('Camiseta Marina', $content->title);
        $this->assertContains('Composición pendiente de confirmar por una persona.', $content->warnings());
        $this->assertContains('Cuidados pendientes de confirmar.', $content->warnings());
    }

    public function test_la_propuesta_sugiere_handle_y_etiquetas_desde_datos_confirmados(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->withConfirmedFacts()->create([
            'source_name' => 'Camiseta Marina',
            'created_by' => $operadora->getKey(),
        ]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create();

        $content = $this->service()->createLocalDraft($product, $operadora);

        $this->assertSame('camiseta-marina', $content->handle);
        $this->assertContains('Blanco', $content->tags());
        $this->assertContains('Dies de Platja', $content->tags());
    }

    public function test_editar_una_propuesta_no_aprobada_actualiza_la_misma_version(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $first = $this->service()->createLocalDraft($product, $operadora);
        $updated = $this->service()->saveProposal($product, ['title' => 'Título corregido'], $operadora);

        $this->assertSame($first->getKey(), $updated->getKey());
        $this->assertSame(1, $updated->version);
        $this->assertSame('Título corregido', $updated->refresh()->title);
        $this->assertSame(1, $product->contents()->count());
    }

    public function test_editar_una_version_aprobada_crea_una_version_nueva(): void
    {
        $operadora = $this->operadora();
        $responsable = $this->responsable();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        // La propuesta local nace sin descripción (no se inventa contenido), así
        // que la persona la escribe antes de que se pueda aprobar.
        $content = $this->service()->createLocalDraft($product, $operadora);
        $content = $this->service()->saveProposal($product, [
            'html_description' => '<p>Descripción confirmada por una persona.</p>',
        ], $operadora);
        $this->service()->approve($content, $responsable);

        $newVersion = $this->service()->saveProposal($product, ['title' => 'Otro título'], $operadora);

        $this->assertSame(2, $newVersion->version);
        $this->assertNotSame($content->getKey(), $newVersion->getKey());
        // La versión aprobada se conserva intacta.
        $this->assertSame($content->title, $content->refresh()->title);
        $this->assertTrue($content->isApproved());
    }

    public function test_aprobar_contenido_marca_la_version_y_queda_auditado(): void
    {
        $operadora = $this->operadora();
        $responsable = $this->responsable();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $content = ProductContent::factory()->forProduct($product)->create();

        $approved = $this->service()->approve($content, $responsable);

        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->approver->is($responsable));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'approved',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_no_se_aprueba_contenido_sin_titulo(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $content = ProductContent::factory()->forProduct($product)->create(['title' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->approve($content, $this->responsable());
    }

    public function test_no_se_aprueba_contenido_sin_descripcion(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $content = ProductContent::factory()->forProduct($product)->create(['html_description' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->approve($content, $this->responsable());
    }

    public function test_restaurar_recupera_la_ultima_version_aprobada_como_nueva(): void
    {
        $operadora = $this->operadora();
        $responsable = $this->responsable();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $content = ProductContent::factory()->forProduct($product)->create(['title' => 'Título aprobado']);
        $this->service()->approve($content, $responsable);

        $this->service()->saveProposal($product, ['title' => 'Título sin aprobar'], $operadora);

        $restored = $this->service()->restoreFromApproved($product, $operadora);

        $this->assertSame('Título aprobado', $restored->title);
        $this->assertSame(3, $restored->version);
        $this->assertFalse($restored->isApproved());
    }

    public function test_no_se_puede_restaurar_sin_una_version_aprobada(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        ProductContent::factory()->forProduct($product)->create();

        $this->expectException(RuntimeException::class);

        $this->service()->restoreFromApproved($product, $operadora);
    }

    public function test_el_contenido_guardado_se_sanitiza(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $content = $this->service()->saveProposal($product, [
            'html_description' => '<p>Texto</p><script>alert(1)</script><h1>No</h1>',
        ], $operadora);

        $this->assertStringNotContainsString('<script>', $content->html_description);
        $this->assertStringNotContainsString('<h1>', $content->html_description);
        $this->assertStringContainsString('<p>Texto</p>', $content->html_description);
    }

    public function test_aprobar_la_ficha_marca_tambien_el_contenido(): void
    {
        $operadora = $this->operadora();
        $responsable = $this->responsable();

        $product = Product::factory()->withConfirmedFacts()->inReview()->create([
            'price' => 25.00,
            'created_by' => $operadora->getKey(),
        ]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create(['sku' => 'X-1']);
        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();
        ProductContent::factory()->forProduct($product)->create(['handle' => 'x-1']);

        app(ProductService::class)->approve($product, $responsable);

        $this->assertSame(ProductStatus::Approved, $product->refresh()->status);
        $this->assertTrue($product->contentFor()->isApproved());
        $this->assertTrue($product->approver->is($responsable));
        $this->assertNotNull($product->approved_at);
    }

    public function test_no_se_aprueba_una_ficha_con_bloqueantes(): void
    {
        $product = Product::factory()->inReview()->create(['price' => null]);

        $this->expectException(RuntimeException::class);

        app(ProductService::class)->approve($product, $this->responsable());
    }

    public function test_no_se_aprueba_una_ficha_en_borrador(): void
    {
        $product = Product::factory()->draft()->create(['price' => 10]);

        $this->expectException(RuntimeException::class);

        app(ProductService::class)->approve($product, $this->responsable());
    }

    public function test_el_contenido_por_idioma_es_independiente(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->service()->saveProposal($product, ['title' => 'Castellano'], $operadora, Locale::Es);
        $this->service()->saveProposal($product, ['title' => 'English'], $operadora, Locale::En);

        $this->assertSame('Castellano', $product->contentFor(Locale::Es)->title);
        $this->assertSame('English', $product->contentFor(Locale::En)->title);
        $this->assertSame(2, $product->contents()->count());
    }

    public function test_devolver_a_revision_queda_auditado(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->approved()->create([
            'price' => 10,
            'created_by' => $operadora->getKey(),
        ]);

        $result = app(ProductService::class)->returnToReview($product, $operadora);

        $this->assertTrue($result);
        $this->assertSame(ProductStatus::Review, $product->refresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'status_changed',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_marcar_como_sincronizando_queda_auditado_y_preparado(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->approved()->create([
            'price' => 10,
            'created_by' => $operadora->getKey(),
        ]);

        app(ProductService::class)->markAsSyncing($product, $operadora);

        $this->assertSame(ProductStatus::Syncing, $product->refresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'sync_requested',
            'subject_id' => $product->getKey(),
        ]);
    }
}
