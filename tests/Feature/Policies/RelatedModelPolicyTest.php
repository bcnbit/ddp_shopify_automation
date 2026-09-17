<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\SyncAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatedModelPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_operadora_edita_variantes_de_sus_fichas(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($operadora->can('update', $variant));
    }

    public function test_la_operadora_no_edita_variantes_de_fichas_ajenas(): void
    {
        $operadora = $this->operadora();
        $ajena = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);
        $variant = ProductVariant::factory()->forProduct($ajena)->create();

        $this->assertFalse($operadora->can('update', $variant));
    }

    public function test_no_se_borra_la_ultima_variante_vendible(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertFalse($operadora->can('delete', $variant));
    }

    public function test_se_puede_borrar_una_variante_si_queda_otra(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $variant = ProductVariant::factory()->forProduct($product)->combination('Blanco', 'S')->create();
        ProductVariant::factory()->forProduct($product)->combination('Negro', 'L')->create();

        $this->assertTrue($operadora->can('delete', $variant));
    }

    public function test_la_operadora_subir_medios_a_sus_fichas_pero_no_a_ajenas(): void
    {
        $operadora = $this->operadora();
        $propia = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $ajena = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);

        $this->assertTrue($operadora->can('create', [ProductMedia::class, $propia]));
        $this->assertFalse($operadora->can('create', [ProductMedia::class, $ajena]));
    }

    public function test_no_se_borra_un_medio_ya_enviado_a_shopify(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $media = ProductMedia::factory()->forProduct($product)->uploaded()->create();

        $this->assertFalse($admin->can('delete', $media));
    }

    public function test_se_puede_borrar_un_medio_local(): void
    {
        $product = Product::factory()->create();
        $media = ProductMedia::factory()->forProduct($product)->create();

        $this->assertTrue($this->admin()->can('delete', $media));
    }

    public function test_no_se_edita_una_version_de_contenido_ya_aprobada(): void
    {
        $responsable = $this->responsable();
        $product = Product::factory()->create();
        $content = ProductContent::factory()->forProduct($product)->approved()->create();

        $this->assertFalse($responsable->can('update', $content));
    }

    public function test_la_operadora_no_aprueba_contenido(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $content = ProductContent::factory()->forProduct($product)->create();

        $this->assertFalse($operadora->can('approve', $content));
        $this->assertTrue($this->responsable()->can('approve', $content));
    }

    public function test_la_operadora_no_reintenta_sincronizaciones(): void
    {
        $product = Product::factory()->status(ProductStatus::SyncFailed)->create();
        $attempt = SyncAttempt::factory()->forProduct($product)->failed()->create();

        $this->assertFalse($this->operadora()->can('retry', $attempt));
        $this->assertTrue($this->admin()->can('retry', $attempt));
    }

    public function test_no_se_reintenta_un_intento_que_no_es_reintentable(): void
    {
        $product = Product::factory()->create();
        $attempt = SyncAttempt::factory()->forProduct($product)->failed(retryable: false)->create();

        $this->assertFalse($this->admin()->can('retry', $attempt));
    }

    public function test_un_admin_puede_gestionar_usuarios_pero_no_autodestruirse(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $this->operadora()));
        $this->assertFalse($admin->can('delete', $admin));
        $this->assertFalse($admin->can('manageRoles', $admin));
    }

    public function test_la_operadora_no_gestiona_usuarios(): void
    {
        $operadora = $this->operadora();

        $this->assertFalse($operadora->can('create', User::class));
        $this->assertFalse($operadora->can('update', $this->admin()));
    }
}
