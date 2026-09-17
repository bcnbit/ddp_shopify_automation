<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gestión de imágenes y variantes desde la ficha (RFC-0002 / RFC-0005).
 *
 * Se ejercita el componente Livewire real porque ahí es donde se cruzan el
 * formulario, el servicio de dominio y las reglas de autorización.
 */
class ProductRelationManagersTest extends TestCase
{
    private function productFor(User $user): Product
    {
        return Product::factory()->create([
            'internal_reference' => 'DDP-4000',
            'created_by' => $user->getKey(),
        ]);
    }

    public function test_la_operadora_subte_una_imagen_desde_la_ficha(): void
    {
        Storage::fake('media');

        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(MediaRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('create', data: [
                'upload' => UploadedFile::fake()->image('camiseta.jpg', 1200, 1200),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $product->media()->count());

        $media = $product->media()->first();
        $this->assertTrue($media->is_primary, 'La primera imagen entra como portada.');
        Storage::disk('media')->assertExists($media->path);
    }

    public function test_la_tabla_de_imagenes_muestra_el_estado_del_alt(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();
        ProductMedia::factory()->forProduct($product)->create(['sort_order' => 1]);

        Livewire::actingAs($operadora)
            ->test(MediaRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->assertOk()
            ->assertCanSeeTableRecords($product->media()->get());
    }

    public function test_el_responsable_genera_variantes_manuales_desde_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('create', data: [
                'sku' => 'DDP-4000-BLA-M',
                'option1_name' => 'Color',
                'option1_value' => 'Blanco',
                'option2_name' => 'Talla',
                'option2_value' => 'M',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $product->variants()->count());
        $this->assertSame('DDP-4000-BLA-M', $product->variants()->first()->sku);
    }

    public function test_la_tabla_de_variantes_se_renderiza_con_datos(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);
        $variant = ProductVariant::factory()->forProduct($product)->combination('Arena', 'L')->create();

        Livewire::actingAs($operadora)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
            ->assertOk()
            ->assertCanSeeTableRecords([$variant]);
    }

    public function test_no_se_puede_anadir_variante_a_una_ficha_ajena(): void
    {
        $product = $this->productFor($this->operadora());
        $otra = $this->operadora();

        $this->assertFalse($otra->can('update', $product));
    }
}
