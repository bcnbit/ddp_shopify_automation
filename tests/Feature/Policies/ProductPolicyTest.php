<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_operadora_puede_crear_fichas(): void
    {
        $this->assertTrue($this->operadora()->can('create', Product::class));
    }

    public function test_la_operadora_ve_y_edita_sus_propias_fichas(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->assertTrue($operadora->can('view', $product));
        $this->assertTrue($operadora->can('update', $product));
    }

    public function test_la_operadora_no_ve_ni_edita_fichas_ajenas(): void
    {
        $operadora = $this->operadora();
        $ajena = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);

        $this->assertFalse($operadora->can('view', $ajena));
        $this->assertFalse($operadora->can('update', $ajena));
    }

    public function test_el_responsable_ve_y_edita_cualquier_ficha(): void
    {
        $responsable = $this->responsable();
        $product = Product::factory()->create(['created_by' => $this->operadora()->getKey()]);

        $this->assertTrue($responsable->can('view', $product));
        $this->assertTrue($responsable->can('update', $product));
    }

    public function test_la_operadora_no_puede_aprobar_su_propia_ficha(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->inReview()->create(['created_by' => $operadora->getKey()]);

        $this->assertFalse($operadora->can('approve', $product));
    }

    public function test_el_responsable_puede_aprobar_una_ficha_en_revision(): void
    {
        $product = Product::factory()->inReview()->create();

        $this->assertTrue($this->responsable()->can('approve', $product));
    }

    public function test_no_se_aprueba_una_ficha_que_no_esta_en_revision(): void
    {
        $product = Product::factory()->draft()->create();

        $this->assertFalse($this->responsable()->can('approve', $product));
    }

    public function test_la_operadora_no_puede_publicar_ni_configurar(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->syncedToShopify()->create(['created_by' => $operadora->getKey()]);

        $this->assertFalse($operadora->can('publish', $product));
        $this->assertFalse($operadora->can('settings.manage'));
    }

    public function test_el_responsable_no_puede_publicar_una_ficha_que_no_es_borrador_de_shopify(): void
    {
        $responsable = $this->responsable();
        $product = Product::factory()->draft()->create();

        $this->assertFalse($responsable->can('publish', $product));
    }

    public function test_el_administrador_puede_publicar_un_borrador_de_shopify(): void
    {
        $product = Product::factory()->syncedToShopify()->create();

        $this->assertTrue($this->admin()->can('publish', $product));
    }

    public function test_la_operadora_puede_enviar_como_borrador_sus_fichas(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->approved()->create(['created_by' => $operadora->getKey()]);

        $this->assertTrue($operadora->can('sync', $product));
    }

    public function test_la_operadora_no_puede_enviar_fichas_ajenas(): void
    {
        $operadora = $this->operadora();
        $ajena = Product::factory()->approved()->create(['created_by' => $this->operadora()->getKey()]);

        $this->assertFalse($operadora->can('sync', $ajena));
    }

    public function test_una_ficha_archivada_no_admite_edicion_ni_sincronizacion(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->status(ProductStatus::Archived)->create();

        $this->assertFalse($admin->can('update', $product));
        $this->assertFalse($admin->can('sync', $product));
        $this->assertFalse($admin->can('approve', $product));
    }

    public function test_no_se_elimina_una_ficha_ya_sincronizada(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->syncedToShopify()->create();

        $this->assertFalse($admin->can('delete', $product));
    }

    public function test_se_puede_eliminar_una_ficha_local_sin_sincronizar(): void
    {
        $product = Product::factory()->draft()->create();

        $this->assertTrue($this->admin()->can('delete', $product));
    }

    public function test_la_operadora_no_consulta_la_auditoria_de_una_ficha(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->assertFalse($operadora->can('viewAudit', $product));
        $this->assertTrue($this->admin()->can('viewAudit', $product));
    }

    public function test_solo_quien_puede_reintentar_usa_el_permiso_correcto(): void
    {
        $product = Product::factory()->status(ProductStatus::SyncFailed)->create();

        $this->assertFalse($this->operadora()->can('retrySync', $product));
        $this->assertTrue($this->admin()->can('retrySync', $product));
    }

    public function test_un_usuario_sin_rol_no_puede_nada(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['created_by' => $user->getKey()]);

        $this->assertFalse($user->can('create', Product::class));
        $this->assertFalse($user->can('view', $product));
        $this->assertFalse($user->can('update', $product));
        $this->assertFalse($user->can('sync', $product));
    }

    public function test_un_usuario_inactivo_pierde_el_acceso_aunque_tenga_rol(): void
    {
        $operadora = User::factory()->operadora()->inactive()->create();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $this->assertFalse($operadora->can('create', Product::class));
        $this->assertFalse($operadora->can('view', $product));
        $this->assertFalse($operadora->can('update', $product));
    }
}
