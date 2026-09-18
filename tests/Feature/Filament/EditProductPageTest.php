<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ProductStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\GenerateProductContentJob;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guardado automático y acciones de la ficha (RFC-0002).
 *
 * Se ejercita el componente Livewire real, no sólo el servicio, porque el
 * criterio de aceptación es que abandonar el navegador no pierda datos.
 */
class EditProductPageTest extends TestCase
{
    private function productFor(User $user): Product
    {
        return Product::factory()->create([
            'created_by' => $user->getKey(),
            'source_name' => 'Camiseta inicial',
            'price' => 20.00,
        ]);
    }

    public function test_el_guardado_automatico_persiste_los_cambios(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm([
                'source_name' => 'Camiseta corregida',
                'ai_base_description' => 'Inspirada en los atardeceres de la cala.',
            ])
            ->call('autoSave');

        $this->assertSame('Camiseta corregida', $product->refresh()->source_name);
        $this->assertSame('Inspirada en los atardeceres de la cala.', $product->ai_base_description);
    }

    /**
     * RFC-0008 sustituyó el campo libre de composición por un mantenimiento.
     *
     * La columna sigue existiendo como respaldo de fichas antiguas, pero ya no se
     * escribe desde el formulario: si alguien colara la clave en el estado del
     * formulario, el guardado automático debe ignorarla en lugar de sobrescribir
     * el dato confirmado del catálogo.
     */
    public function test_el_formulario_ya_no_escribe_la_composicion_libre(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $product->update(['composition' => '100% algodón peinado']);

        $this->assertNotContains('composition', ProductResource::editableProductAttributes());

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['source_name' => 'Otro nombre'])
            ->call('autoSave');

        $this->assertSame('100% algodón peinado', $product->refresh()->composition);
    }

    public function test_el_guardado_automatico_deja_rastro_en_auditoria(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['source_name' => 'Otro nombre'])
            ->call('autoSave');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'updated',
            'subject_id' => $product->getKey(),
            'subject_type' => Product::class,
            'user_id' => $operadora->getKey(),
        ]);
    }

    public function test_el_guardado_automatico_no_crea_versiones_de_contenido(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        ProductContent::factory()->forProduct($product)->create();

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['source_name' => 'Cambio de nombre'])
            ->call('autoSave');

        // El contenido vive en product_content: el autoguardado no debe tocarlo.
        $this->assertSame(1, $product->contents()->count());
        $this->assertSame('Cambio de nombre', $product->refresh()->source_name);
    }

    public function test_la_ficha_ajena_ni_siquiera_se_puede_cargar(): void
    {
        $product = $this->productFor($this->operadora());
        $otra = $this->operadora();

        // La consulta del recurso está acotada al usuario, así que la ficha
        // ajena no existe para él: no llega a montarse la pantalla.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($otra)
            ->test(EditProduct::class, ['record' => $product->getKey()]);
    }

    public function test_el_guardado_automatico_de_la_operadora_no_alcanza_fichas_ajenas(): void
    {
        $product = $this->productFor($this->operadora());

        // El servicio se defiende por sí mismo aunque se le llame directamente,
        // sin pasar por la pantalla.
        $otra = $this->operadora();

        $this->assertFalse($otra->can('update', $product));

        $this->assertSame('Camiseta inicial', $product->refresh()->source_name);
    }

    public function test_se_puede_generar_una_propuesta_sin_ia_desde_la_pantalla(): void
    {
        // La acción sin IA reutiliza los datos confirmados y no llama a ningún
        // proveedor, así que funciona sin configurar OpenRouter.
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('localDraft');

        $this->assertSame(1, $product->contents()->count());
        $this->assertSame('Camiseta inicial', $product->contentFor()->title);
    }

    public function test_la_accion_de_generar_con_ia_encola_y_marca_la_ficha(): void
    {
        Queue::fake();

        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('generateProposal');

        // No se genera nada en línea: se encola y la ficha pasa a «generando».
        Queue::assertPushed(GenerateProductContentJob::class);
        $this->assertSame(ProductStatus::Generating, $product->refresh()->status);
        $this->assertSame(0, $product->contents()->count());
    }

    public function test_la_operadora_puede_restaurar_una_propuesta_aprobada(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $approved = ProductContent::factory()->forProduct($product)->create([
            'title' => 'Título aprobado',
            'approved_at' => now(),
        ]);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('restoreProposal');

        $restored = $product->contentFor();

        $this->assertSame('Título aprobado', $restored->title);
        $this->assertFalse($restored->isApproved());
        $this->assertNotSame($approved->getKey(), $restored->getKey());
    }

    public function test_la_operadora_no_puede_aprobar_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->inReview()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20,
        ]);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionHidden('approve');
    }

    public function test_el_responsable_ve_la_accion_de_aprobar_con_la_ficha_lista(): void
    {
        $responsable = $this->responsable();
        $product = Product::factory()->withConfirmedFacts()->inReview()->create(['price' => 20]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create(['sku' => 'R-1']);
        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();
        ProductContent::factory()->forProduct($product)->create(['handle' => 'r-1']);

        Livewire::actingAs($responsable)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionVisible('approve');
    }

    public function test_la_accion_de_enviar_esta_deshabilitada_si_faltan_datos(): void
    {
        // Ficha aprobada pero sin variantes ni contenido: no puede enviarse.
        $operadora = $this->operadora();
        $product = Product::factory()->approved()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20,
        ]);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionDisabled('sendToShopify');
    }

    public function test_la_accion_de_enviar_se_habilita_con_la_ficha_completa(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->withConfirmedFacts()->approved()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20,
        ]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create(['sku' => 'S-1']);
        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();
        ProductContent::factory()->forProduct($product)->create(['handle' => 's-1']);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionEnabled('sendToShopify');
    }

    public function test_el_boton_cambia_de_etiqueta_si_la_ficha_ya_esta_en_shopify(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->syncedToShopify()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20,
        ]);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionExists('sendToShopify');
    }

    public function test_la_pantalla_de_alta_crea_la_ficha_y_redirige_a_edicion(): void
    {
        $operadora = $this->operadora();

        Livewire::actingAs($operadora)
            ->test(CreateProduct::class)
            ->fillForm([
                'internal_reference' => 'ddp 7777',
                'source_name' => 'Nueva camiseta',
                'price' => 19.90,
                'product_type' => 'camiseta',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('internal_reference', 'DDP-7777')->firstOrFail();

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame($operadora->getKey(), $product->created_by);
    }

    public function test_la_referencia_interna_no_se_puede_repetir(): void
    {
        $operadora = $this->operadora();
        Product::factory()->create(['internal_reference' => 'DDP-1111']);

        Livewire::actingAs($operadora)
            ->test(CreateProduct::class)
            ->fillForm([
                'internal_reference' => 'DDP-1111',
                'source_name' => 'Duplicada',
                'price' => 10,
                'product_type' => 'camiseta',
            ])
            ->call('create')
            ->assertHasFormErrors(['internal_reference']);
    }
}
