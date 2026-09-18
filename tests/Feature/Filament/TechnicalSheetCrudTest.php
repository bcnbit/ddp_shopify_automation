<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\TechnicalSheets\Pages\CreateCare;
use App\Filament\Resources\TechnicalSheets\Pages\CreateComposition;
use App\Filament\Resources\TechnicalSheets\Pages\CreateFit;
use App\Filament\Resources\TechnicalSheets\Pages\CreateSizeGuide;
use App\Filament\Resources\TechnicalSheets\Pages\EditCare;
use App\Filament\Resources\TechnicalSheets\Pages\EditComposition;
use App\Filament\Resources\TechnicalSheets\Pages\EditFit;
use App\Filament\Resources\TechnicalSheets\Pages\EditSizeGuide;
use App\Filament\Resources\TechnicalSheets\Pages\ListCares;
use App\Filament\Resources\TechnicalSheets\Pages\ListCompositions;
use App\Filament\Resources\TechnicalSheets\Pages\ListFits;
use App\Filament\Resources\TechnicalSheets\Pages\ListSizeGuides;
use App\Models\Product;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Models\User;
use App\Services\Products\ProductService;
use App\Support\Products\TechnicalSheetSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRUD de los mantenimientos de ficha técnica (RFC-0008).
 *
 * Recorre las cuatro operaciones sobre los cuatro mantenimientos, con la
 * operadora, que es quien prepara las fichas. El objetivo es que gestionar el
 * catálogo no dependa de un administrador técnico.
 */
class TechnicalSheetCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las cuatro parejas de páginas de alta y edición, por mantenimiento.
     *
     * @return array<string, array{create: class-string, edit: class-string, list: class-string, model: class-string, content: array<string, mixed>}>
     */
    public static function mantenimientos(): array
    {
        return [
            'composiciones' => [
                'create' => CreateComposition::class,
                'edit' => EditComposition::class,
                'list' => ListCompositions::class,
                'model' => TechnicalSheetComposition::class,
                'content' => ['content_text' => '100% algodón'],
            ],
            'ajustes' => [
                'create' => CreateFit::class,
                'edit' => EditFit::class,
                'list' => ListFits::class,
                'model' => TechnicalSheetFit::class,
                'content' => ['content_text' => 'Corte regular.'],
            ],
            'cuidados' => [
                'create' => CreateCare::class,
                'edit' => EditCare::class,
                'list' => ListCares::class,
                'model' => TechnicalSheetCare::class,
                'content' => ['content_text' => "Lavar a 30 ºC.\nNo usar secadora."],
            ],
            'guias' => [
                'create' => CreateSizeGuide::class,
                'edit' => EditSizeGuide::class,
                'list' => ListSizeGuides::class,
                'model' => TechnicalSheetSizeGuide::class,
                'content' => [
                    'content_html' => '<table><tbody><tr><td>M</td><td>102</td></tr></tbody></table>',
                    'intro_note' => 'Medidas en centímetros.',
                ],
            ],
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $content
     */
    private function makeEntry(string $model, array $content): object
    {
        return $model::factory()->create([
            'code' => 'COD-1',
            'name' => 'Original',
            ...$content,
        ]);
    }

    public function test_la_operadora_lista_los_cuatro_mantenimientos(): void
    {
        $operadora = $this->operadora();

        foreach (self::mantenimientos() as $label => $pages) {
            $entry = $this->makeEntry($pages['model'], $pages['content']);
            $this->actingAs($operadora)->get('/admin/technical-sheets/'.$this->slugFor($label))->assertOk();
        }
    }

    public function test_la_operadora_crea_los_cuatro_mantenimientos(): void
    {
        $operadora = $this->operadora();

        foreach (self::mantenimientos() as $label => $pages) {
            Livewire::actingAs($operadora)
                ->test($pages['create'])
                ->fillForm([
                    'name' => "Nuevo {$label}",
                    'code' => 'COD-'.strtoupper($label),
                    'is_active' => true,
                    ...$pages['content'],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $this->assertSame(
                1,
                $pages['model']::query()->where('code', 'COD-'.strtoupper($label))->count(),
                "No se creó el mantenimiento de {$label}.",
            );
        }
    }

    public function test_la_operadora_edita_los_cuatro_mantenimientos(): void
    {
        $operadora = $this->operadora();

        foreach (self::mantenimientos() as $label => $pages) {
            $entry = $this->makeEntry($pages['model'], $pages['content']);

            Livewire::actingAs($operadora)
                ->test($pages['edit'], ['record' => $entry->getKey()])
                ->fillForm(['name' => 'Nombre corregido'])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame('Nombre corregido', $entry->refresh()->name);
        }
    }

    public function test_editar_el_contenido_sube_la_version(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetComposition::factory()->create(['code' => 'COD-V', 'content_text' => '100% algodón']);

        $this->assertSame(1, $entry->version);

        Livewire::actingAs($operadora)
            ->test(EditComposition::class, ['record' => $entry->getKey()])
            ->fillForm(['content_text' => '100% algodón peinado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $entry->refresh()->version);
    }

    public function test_la_operadora_borra_los_cuatro_mantenimientos(): void
    {
        $operadora = $this->operadora();

        foreach (self::mantenimientos() as $label => $pages) {
            $entry = $this->makeEntry($pages['model'], $pages['content']);

            Livewire::actingAs($operadora)
                ->test($pages['edit'], ['record' => $entry->getKey()])
                ->callAction('delete');

            $this->assertFalse(
                $pages['model']::query()->whereKey($entry->getKey())->exists(),
                "No se borró el mantenimiento de {$label}.",
            );
        }
    }

    public function test_se_pueden_duplicar_mantenimientos_para_partir_de_uno_existente(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetComposition::factory()->create([
            'code' => 'COMP-ALG',
            'name' => 'Algodón',
            'content_text' => '100% algodón',
        ]);

        Livewire::actingAs($operadora)
            ->test(ListCompositions::class)
            ->callTableAction('replicate', $entry, data: [
                'code' => 'COMP-LINO',
                'name' => 'Lino',
                'is_active' => true,
            ]);

        $copia = TechnicalSheetComposition::query()->where('code', 'COMP-LINO')->first();

        $this->assertNotNull($copia, 'No se creó la copia.');
        $this->assertSame('100% algodón', $copia->content_text);
        $this->assertNotSame($entry->getKey(), $copia->getKey());
        // El original queda intacto.
        $this->assertSame('COMP-ALG', $entry->refresh()->code);
    }

    public function test_duplicar_sugiere_un_codigo_libre_y_nace_inactivo(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetComposition::factory()->create(['code' => 'COMP-ALG', 'name' => 'Algodón']);

        // Primera copia: `-COPIA`.
        Livewire::actingAs($operadora)
            ->test(ListCompositions::class)
            ->callTableAction('replicate', $entry, data: [
                'code' => 'COMP-ALG-COPIA',
                'name' => 'Algodón (copia)',
                'is_active' => false,
            ]);

        $primera = TechnicalSheetComposition::query()->where('code', 'COMP-ALG-COPIA')->first();
        $this->assertNotNull($primera);
        $this->assertFalse($primera->is_active, 'Una copia sin revisar no debe ofrecerse en fichas nuevas.');
    }

    public function test_duplicar_no_permite_repetir_el_codigo_del_original(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetComposition::factory()->create(['code' => 'COMP-ALG', 'name' => 'Algodón']);

        Livewire::actingAs($operadora)
            ->test(ListCompositions::class)
            ->callTableAction('replicate', $entry, data: [
                'code' => 'COMP-ALG',
                'name' => 'Otra',
                'is_active' => false,
            ])
            ->assertHasTableActionErrors(['code']);

        $this->assertSame(1, TechnicalSheetComposition::query()->where('code', 'COMP-ALG')->count());
    }

    public function test_duplicar_una_guia_de_tallas_conserva_su_html(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetSizeGuide::factory()->create([
            'code' => 'TALLA-A',
            'content_html' => '<table><tbody><tr><td>S</td><td>96</td></tr></tbody></table>',
        ]);

        Livewire::actingAs($operadora)
            ->test(ListSizeGuides::class)
            ->callTableAction('replicate', $entry, data: [
                'code' => 'TALLA-B',
                'name' => 'Copia',
                'is_active' => false,
            ]);

        $copia = TechnicalSheetSizeGuide::query()->where('code', 'TALLA-B')->first();

        $this->assertNotNull($copia);
        $this->assertStringContainsString('<td>96</td>', (string) $copia->content_html);
    }

    /**
     * Editar un maestro no puede alterar una ficha que ya lo usa.
     *
     * Es la garantía central de la enmienda, comprobada ahora por la vía del
     * panel (que es la que usa una persona) y no sólo por el modelo.
     */
    public function test_editar_desde_el_panel_no_altera_las_fichas_que_ya_lo_usan(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetComposition::factory()->create(['code' => 'COMP-ALG', 'content_text' => '100% algodón']);

        $product = Product::factory()->create([
            'created_by' => $operadora->getKey(),
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $entry->getKey(),
        ], $operadora);

        Livewire::actingAs($operadora)
            ->test(EditComposition::class, ['record' => $entry->getKey()])
            ->fillForm(['content_text' => '100% poliéster'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            '100% algodón',
            TechnicalSheetSelection::forProduct($product->refresh())->effectiveComposition(),
            'La ficha debe seguir usando la copia congelada, no el maestro editado.',
        );
    }

    public function test_borrar_un_mantenimiento_no_rompe_las_fichas_que_lo_usan(): void
    {
        $operadora = $this->operadora();
        $entry = TechnicalSheetFit::factory()->create(['code' => 'FIT-1', 'content_text' => 'Corte regular.']);

        $product = Product::factory()->create([
            'created_by' => $operadora->getKey(),
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_fit_id' => $entry->getKey(),
        ], $operadora);

        Livewire::actingAs($operadora)
            ->test(EditFit::class, ['record' => $entry->getKey()])
            ->callAction('delete');

        $this->assertSame(
            'Corte regular.',
            TechnicalSheetSelection::forProduct($product->refresh())->effectiveFit(),
        );
    }

    public function test_la_operadora_puede_desactivar_un_mantenimiento_en_lugar_de_borrarlo(): void
    {
        // Desactivar es la alternativa segura al borrado: deja de ofrecerse en
        // fichas nuevas sin tocar las que ya lo usan.
        $operadora = $this->operadora();
        $entry = TechnicalSheetCare::factory()->create(['code' => 'CARE-1']);

        Livewire::actingAs($operadora)
            ->test(EditCare::class, ['record' => $entry->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($entry->refresh()->is_active);
        $this->assertTrue($entry->exists, 'Desactivar no borra.');
    }

    public function test_el_codigo_de_un_mantenimiento_no_se_puede_repetir_al_crear(): void
    {
        $operadora = $this->operadora();
        TechnicalSheetComposition::factory()->create(['code' => 'COMP-UNICA']);

        Livewire::actingAs($operadora)
            ->test(CreateComposition::class)
            ->fillForm([
                'name' => 'Otra',
                'code' => 'COMP-UNICA',
                'content_text' => '100% lino',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_un_trabajador_sin_permiso_no_gestiona_el_catalogo(): void
    {
        $inactiva = User::factory()->operadora()->inactive()->create();

        $this->assertFalse($inactiva->can('create', TechnicalSheetComposition::class));
        $this->assertFalse($inactiva->can('update', TechnicalSheetComposition::factory()->create()));
    }

    private function slugFor(string $label): string
    {
        return match ($label) {
            'composiciones' => 'compositions',
            'ajustes' => 'fits',
            'cuidados' => 'cares',
            default => 'size-guides',
        };
    }
}
