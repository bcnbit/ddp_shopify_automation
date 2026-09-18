<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Permission;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\TechnicalSheets\Pages\CreateComposition;
use App\Filament\Resources\TechnicalSheets\Pages\CreateSizeGuide;
use App\Models\Product;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetSizeGuide;
use App\Models\User;
use App\Services\Products\ProductService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Apartado de mantenimientos y su reflejo en la ficha (RFC-0008).
 *
 * Cubre los dos criterios de interfaz: que el mantenimiento se pueda gestionar
 * desde el panel y que seleccionarlo se vea en la previsualización de la ficha.
 */
class TechnicalSheetPanelTest extends TestCase
{
    use RefreshDatabase;

    private function productFor(User $user): Product
    {
        return Product::factory()->create([
            'created_by' => $user->getKey(),
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);
    }

    public function test_los_cuatro_recursos_se_descubren_en_el_panel(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        foreach ([
            '/admin/technical-sheets/compositions',
            '/admin/technical-sheets/fits',
            '/admin/technical-sheets/cares',
            '/admin/technical-sheets/size-guides',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_el_responsable_gestiona_una_composicion(): void
    {
        $responsable = $this->responsable();

        Livewire::actingAs($responsable)
            ->test(CreateComposition::class)
            ->fillForm([
                'name' => 'Algodón peinado',
                'code' => 'COMP-PEINADO',
                'content_text' => '100% algodón peinado',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('technical_sheet_compositions', [
            'code' => 'COMP-PEINADO',
            'content_text' => '100% algodón peinado',
        ]);
    }

    /**
     * La operadora gestiona los mantenimientos.
     *
     * Antes eran de sólo lectura para ella. Se cambió a petición expresa: es
     * quien prepara las fichas y quien detecta que falta una composición o un
     * perfil de cuidados, así que obligarla a pedirlo a otra persona sólo añadía
     * una espera. La garantía de la enmienda no depende de quién edita, sino de
     * la copia congelada: cambiar un mantenimiento sigue sin alterar las fichas
     * que ya lo usan (ver `TechnicalSheetSnapshotTest`).
     */
    public function test_la_operadora_puede_gestionar_mantenimientos(): void
    {
        $operadora = $this->operadora();

        $this->assertTrue($operadora->can('viewAny', TechnicalSheetComposition::class));
        $this->assertTrue($operadora->can('create', TechnicalSheetComposition::class));
        $this->assertTrue($operadora->can('update', TechnicalSheetComposition::factory()->create()));
        $this->assertTrue($operadora->can('delete', TechnicalSheetComposition::factory()->create()));

        $this->actingAs($operadora)
            ->get('/admin/technical-sheets/compositions/create')
            ->assertOk();
    }

    /**
     * Y sigue sin poder hacer lo que no le corresponde.
     *
     * El cambio anterior no debe arrastrar otros permisos: la operadora gestiona
     * el catálogo de mantenimientos, pero no aprueba fichas ni publica.
     */
    public function test_gestionar_mantenimientos_no_le_da_otros_permisos(): void
    {
        $operadora = $this->operadora();

        $this->assertFalse($operadora->hasPermissionTo(Permission::ProductsApprove->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::ProductsPublish->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::UsersManage->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::SettingsManage->value));
    }

    public function test_el_responsable_si_puede_gestionar_mantenimientos(): void
    {
        $responsable = $this->responsable();

        $this->assertTrue($responsable->can('create', TechnicalSheetComposition::class));
        $this->assertTrue($responsable->can('create', TechnicalSheetSizeGuide::class));
    }

    public function test_la_guia_de_tallas_se_guarda_sanitizada_desde_el_panel(): void
    {
        $responsable = $this->responsable();

        Livewire::actingAs($responsable)
            ->test(CreateSizeGuide::class)
            ->fillForm([
                'name' => 'Camiseta adulto',
                'code' => 'TALLA-CAM-AD',
                'content_html' => '<table><tbody><tr><td onclick="x()">S</td><td>96</td></tr></tbody></table>',
                'intro_note' => 'Medidas en centímetros.',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $stored = (string) TechnicalSheetSizeGuide::where('code', 'TALLA-CAM-AD')->first()?->content_html;

        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<td>S</td>', $stored);
    }

    public function test_el_codigo_de_un_mantenimiento_no_se_puede_repetir(): void
    {
        TechnicalSheetComposition::factory()->create(['code' => 'COMP-UNICA']);

        Livewire::actingAs($this->responsable())
            ->test(CreateComposition::class)
            ->fillForm([
                'name' => 'Otra',
                'code' => 'COMP-UNICA',
                'content_text' => '100% lino',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    /**
     * Criterio 1: seleccionar una guía de tallas inserta su tabla en la ficha.
     */
    public function test_seleccionar_una_guia_muestra_su_tabla_en_la_previsualizacion(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $guide = TechnicalSheetSizeGuide::factory()->create([
            'content_html' => '<table><tbody><tr><td>L</td><td>108</td></tr></tbody></table>',
        ]);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['technical_sheet_size_guide_id' => $guide->getKey()])
            ->assertOk()
            ->assertSeeHtml('<td>108</td>');
    }

    public function test_seleccionar_mantenimientos_muestra_su_contenido_en_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón peinado']);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['technical_sheet_composition_id' => $composition->getKey()])
            ->assertSee('100% algodón peinado');
    }

    /**
     * Criterio 4: un mantenimiento inactivo no aparece en fichas nuevas.
     */
    public function test_un_mantenimiento_inactivo_no_se_ofrece_en_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        TechnicalSheetComposition::factory()->create([
            'code' => 'VISIBLE',
            'name' => 'Composición visible',
        ]);
        TechnicalSheetComposition::factory()->inactive()->create([
            'code' => 'OCULTA',
            'name' => 'Composición oculta',
        ]);

        $component = Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()]);

        $options = $component->instance()->form->getComponent('technical_sheet_composition_id')->getOptions();

        $this->assertContains('Composición visible', array_values($options));
        $this->assertNotContains('Composición oculta', array_values($options));
    }

    /**
     * Un mantenimiento desactivado después de asignarlo sigue visible.
     *
     * Es el caso que evita una pérdida silenciosa: si desapareciera de las
     * opciones, el próximo guardado automático borraría la selección sin que
     * nadie lo hubiera pedido.
     */
    public function test_un_mantenimiento_desactivado_sigue_visible_si_la_ficha_lo_usa(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $entry = TechnicalSheetComposition::factory()->create([
            'code' => 'SE-DESACTIVA',
            'name' => 'Composición que se desactiva',
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $entry->getKey(),
        ], $operadora);

        $entry->update(['is_active' => false]);

        $component = Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->refresh()->getKey()]);

        $select = $component->instance()->form->getComponent('technical_sheet_composition_id');

        $this->assertArrayHasKey(
            $entry->getKey(),
            $select->getOptions(),
            'El mantenimiento en uso debe seguir entre las opciones aunque se haya desactivado.',
        );
        $this->assertSame('Composición que se desactiva', $select->getOptionLabel());
    }

    public function test_la_ficha_permite_dejar_los_selectores_vacios(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm([
                'technical_sheet_composition_id' => null,
                'technical_sheet_fit_id' => null,
                'technical_sheet_care_id' => null,
                'technical_sheet_size_guide_id' => null,
            ])
            ->call('autoSave')
            ->assertOk();

        $this->assertSame(0, $product->refresh()->technicalSheets()->count());
    }

    public function test_la_ficha_muestra_la_ayuda_de_la_descripcion_base(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        Livewire::actingAs($operadora)
            ->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSee('No se publica literalmente. Se utiliza para mejorar la propuesta generada por IA.');
    }

    public function test_una_ficha_con_mantenimiento_se_edita_sin_errores_de_carga_diferida(): void
    {
        // La Policy del snapshot autoriza por fila, así que la relación necesita
        // `chaperone('product')`. Sin él, esta pantalla fallaría en desarrollo
        // (ver handoff §7.1).
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        $product->update([
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()->create()->getKey(),
        ]);

        Model::preventLazyLoading();

        try {
            Livewire::actingAs($operadora)
                ->test(EditProduct::class, ['record' => $product->getKey()])
                ->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_la_tabla_de_mantenimientos_se_renderiza_con_datos(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();
        TechnicalSheetComposition::factory()->create(['name' => 'Algodón 100%']);

        $this->actingAs($admin)
            ->get('/admin/technical-sheets/compositions')
            ->assertOk()
            ->assertSee('Algodón 100%');
    }

    public function test_los_recursos_de_mantenimiento_no_son_accesibles_sin_sesion(): void
    {
        $this->get('/admin/technical-sheets/compositions')->assertRedirect();
    }
}
