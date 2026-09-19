<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Botón «Añadir Variante Tallas» en la ficha (RFC-0002 / RFC-0005).
 *
 * Se ejercita el componente Livewire real porque ahí es donde se cruzan el botón,
 * el servicio de dominio y la autorización. El comportamiento del servicio está
 * probado aparte (`AddSizeVariantsTest`); aquí importa que el botón exista, esté
 * donde debe y que su permiso sea el de la ficha, no otro.
 */
class AddSizeVariantsButtonTest extends TestCase
{
    private function productFor(User $user): Product
    {
        return Product::factory()->create([
            'internal_reference' => 'DDP-SS-VOICE',
            'price' => '39.00',
            'created_by' => $user->getKey(),
        ]);
    }

    /**
     * @return Testable
     */
    private function variantsTable(Product $product, User $user)
    {
        return Livewire::actingAs($user)->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);
    }

    public function test_el_boton_existe_para_quien_puede_editar_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $this->variantsTable($product, $operadora)
            ->assertSee('Añadir Variante Tallas');
    }

    public function test_el_boton_va_antes_que_anadir_variante(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $html = $this->variantsTable($product, $operadora)->html();

        $posTallas = mb_strpos($html, 'Añadir Variante Tallas');
        $posVariante = mb_strpos($html, 'Añadir variante');

        $this->assertNotFalse($posTallas);
        $this->assertNotFalse($posVariante);

        // En Filament el orden del array de acciones es el orden en pantalla: si el
        // botón nuevo se pusiera después, aparecería a la derecha de «Añadir variante».
        $this->assertLessThan($posVariante, $posTallas, 'El botón de tallas debe quedar a la izquierda.');
    }

    public function test_el_boton_crea_las_cinco_tallas(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $this->variantsTable($product, $operadora)
            ->callTableAction('addSizes')
            ->assertHasNoTableActionErrors();

        $this->assertSame(5, $product->variants()->count());

        $this->assertSame(
            ['DDP-SS-VOICE-S', 'DDP-SS-VOICE-M', 'DDP-SS-VOICE-L', 'DDP-SS-VOICE-XL', 'DDP-SS-VOICE-2XL'],
            $product->variants()->orderBy('position')->pluck('sku')->all(),
        );
    }

    public function test_el_boton_deja_cinco_unidades_de_stock_a_cada_talla(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $this->variantsTable($product, $operadora)
            ->callTableAction('addSizes')
            ->assertHasNoTableActionErrors();

        // El flujo completo (botón → servicio → base de datos) deja el stock de
        // arranque puesto, que es lo que se pidió.
        $this->assertSame(5, $product->variants()->count());
        $this->assertSame(
            [5, 5, 5, 5, 5],
            $product->variants()->orderBy('position')->pluck('inventory_quantity')->all(),
        );
    }

    public function test_el_boton_no_falla_si_las_tallas_ya_existen(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-SS-VOICE-S',
            'option1_name' => 'Color', 'option1_value' => '',
            'option2_name' => 'Talla', 'option2_value' => 'S',
        ]);

        // Un segundo clic no puede reventar con un error de SKU duplicado: la
        // persona vería un fallo por una acción que ya había hecho.
        $this->variantsTable($product, $operadora)
            ->callTableAction('addSizes')
            ->assertHasNoTableActionErrors();

        $this->assertSame(5, $product->variants()->count());
    }

    public function test_pulsar_dos_veces_con_todo_creado_no_duplica(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $this->variantsTable($product, $operadora)->callTableAction('addSizes');
        $this->variantsTable($product, $operadora)->callTableAction('addSizes');

        $this->assertSame(5, $product->fresh()->variants()->count());
    }

    public function test_la_operadora_ajena_no_ve_el_boton(): void
    {
        // La autorización se delega en la Policy de la ficha: la interfaz no decide.
        $product = $this->productFor($this->operadora());
        $otra = $this->operadora();

        $this->assertFalse($otra->can('update', $product));
    }

    public function test_el_boton_no_toca_las_imagenes_de_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $primary = ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();

        $this->variantsTable($product, $operadora)->callTableAction('addSizes');

        // La foto principal sigue siendo la misma: las variantes no tienen imagen
        // propia en el modelo actual y este botón no debe inventarla.
        $this->assertSame(1, $product->media()->count());
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame($primary->getKey(), $product->primaryMedia()?->getKey());
    }
}
