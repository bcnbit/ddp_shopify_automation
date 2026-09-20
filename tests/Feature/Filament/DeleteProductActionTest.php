<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Borrado de una ficha desde el panel (RFC-0002).
 *
 * Se ejercita el componente Livewire real porque es donde se cruzan la acción,
 * el servicio de dominio y la autorización. Que el servicio borre bien ya está
 * probado aparte (`DeleteProductTest`); aquí importa que la pantalla esté bien
 * cableada: que la acción exista, respete la Policy y limpie los ficheros.
 *
 * Quien elimina es el **responsable de catálogo**: la operadora no tiene el
 * permiso `products.delete`, y eso no cambia con esta función.
 */
class DeleteProductActionTest extends TestCase
{
    private function productFor(User $user): Product
    {
        $product = Product::factory()->create([
            'internal_reference' => 'DDP-6000',
            'created_by' => $user->getKey(),
        ]);

        $media = ProductMedia::factory()->forProduct($product)->primary()->create();

        Storage::disk($media->disk)->put($media->path, 'original');
        Storage::disk((string) config('media.disks.derived'))->put($media->path, 'derivado');

        return $product->refresh();
    }

    public function test_el_responsable_elimina_la_ficha_y_los_ficheros(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $responsable = $this->responsable();
        $product = $this->productFor($responsable);
        $path = $product->media->first()->path;

        Livewire::actingAs($responsable)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('delete');

        $this->assertNull(Product::find($product->getKey()));
        Storage::disk('media')->assertMissing($path);
        Storage::disk('media-derived')->assertMissing($path);
    }

    public function test_el_listado_permite_borrar_una_ficha_concreta(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $responsable = $this->responsable();
        $product = $this->productFor($responsable);

        Livewire::actingAs($responsable)
            ->test(ListProducts::class)
            ->callTableAction('delete', $product->getKey());

        $this->assertNull(Product::find($product->getKey()));
    }

    public function test_la_operadora_no_puede_eliminar_fichas(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        // No es un fallo de la pantalla: la operadora no tiene `products.delete`
        // (ver `Permission::byRole()`), y la acción se oculta por la Policy.
        Livewire::actingAs($operadora)
            ->test(ListProducts::class)
            ->assertTableActionHidden('delete', $product->getKey());
    }
}
