<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_la_referencia_interna_al_guardar(): void
    {
        $product = Product::factory()->create(['internal_reference' => ' ddp 1001 ']);

        $this->assertSame('DDP-1001', $product->internal_reference);
    }

    public function test_la_referencia_interna_es_unica(): void
    {
        Product::factory()->create(['internal_reference' => 'DDP-2002']);

        $this->expectException(QueryException::class);

        Product::factory()->create(['internal_reference' => 'ddp-2002']);
    }

    public function test_convierte_los_enum_al_leerlos(): void
    {
        $product = Product::factory()->draft()->create();

        $this->assertInstanceOf(ProductStatus::class, $product->fresh()->status);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_expoone_las_relaciones_del_modelo_de_datos(): void
    {
        $product = Product::factory()->create();

        $this->assertInstanceOf(HasMany::class, $product->variants());
        $this->assertInstanceOf(HasMany::class, $product->media());
        $this->assertInstanceOf(HasMany::class, $product->contents());
        $this->assertInstanceOf(HasMany::class, $product->syncAttempts());
        $this->assertInstanceOf(BelongsTo::class, $product->creator());
    }

    public function test_registra_quien_creo_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->assertTrue($product->creator->is($operadora));
        $this->assertTrue($operadora->createdProducts()->whereKey($product->getKey())->exists());
    }

    public function test_registra_quien_aprobo_la_ficha(): void
    {
        $responsable = $this->responsable();
        $product = Product::factory()->create([
            'approved_by' => $responsable->getKey(),
            'approved_at' => now(),
        ]);

        $this->assertTrue($product->approver->is($responsable));
        $this->assertNotNull($product->approved_at);
        $this->assertTrue($responsable->approvedProducts()->whereKey($product->getKey())->exists());
    }

    public function test_el_precio_se_conserva_con_dos_decimales(): void
    {
        $product = Product::factory()->create(['price' => 24.5]);

        $this->assertSame('24.50', $product->fresh()->price);
    }

    public function test_transition_to_respeta_las_transiciones_permitidas(): void
    {
        $product = Product::factory()->draft()->create();

        $this->assertTrue($product->transitionTo(ProductStatus::Generating));
        $this->assertSame(ProductStatus::Generating, $product->fresh()->status);
    }

    public function test_transition_to_rechaza_publicar_sin_sincronizar(): void
    {
        $product = Product::factory()->draft()->create();

        $this->assertFalse($product->transitionTo(ProductStatus::Published));
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_una_ficha_sincronizada_reporta_su_gid_de_shopify(): void
    {
        $product = Product::factory()->syncedToShopify()->create();

        $this->assertTrue($product->isSyncedWithShopify());
        $this->assertStringStartsWith('gid://shopify/Product/', $product->shopify_product_gid);
        $this->assertNotNull($product->last_synced_at);
    }

    public function test_content_for_devuelve_la_version_mas_reciente(): void
    {
        $product = Product::factory()->create();
        ProductContent::factory()->forProduct($product)->version(1)->create(['title' => 'Versión 1']);
        ProductContent::factory()->forProduct($product)->version(2)->create(['title' => 'Versión 2']);

        $this->assertSame('Versión 2', $product->contentFor()->title);
        $this->assertSame('Versión 1', $product->contentFor(version: 1)->title);
        $this->assertSame(2, $product->latestContentVersion());
    }

    public function test_primary_media_devuelve_la_marcada_como_principal(): void
    {
        $product = Product::factory()->create();
        ProductMedia::factory()->forProduct($product)->create(['sort_order' => 0]);
        $primary = ProductMedia::factory()->forProduct($product)->primary()->create(['sort_order' => 1]);

        $this->assertTrue($product->primaryMedia()->is($primary));
    }

    public function test_el_alcance_owned_by_filtra_por_propietario(): void
    {
        $operadora = $this->operadora();
        $otra = $this->operadora();

        Product::factory()->create(['created_by' => $operadora->getKey()]);
        Product::factory()->create(['created_by' => $otra->getKey()]);

        $this->assertSame(1, Product::query()->ownedBy($operadora)->count());
    }
}
