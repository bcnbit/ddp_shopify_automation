<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Support\Products\TechnicalSheetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mantenimientos de ficha técnica: modelo, versionado y elegibilidad (RFC-0008).
 */
class TechnicalSheetMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_codigo_se_normaliza_a_mayusculas_y_guiones(): void
    {
        $entry = TechnicalSheetComposition::create([
            'code' => 'comp algodón 100',
            'name' => 'Algodón',
            'content_text' => '100% algodón',
        ]);

        $this->assertSame('COMP-ALGODÓN-100', $entry->refresh()->code);
    }

    public function test_un_mantenimiento_nace_en_version_uno(): void
    {
        $entry = TechnicalSheetComposition::create([
            'code' => 'COMP-1',
            'name' => 'Algodón',
            'content_text' => '100% algodón',
        ]);

        $this->assertSame(1, $entry->version);
    }

    public function test_la_version_sube_al_cambiar_el_contenido(): void
    {
        $entry = TechnicalSheetComposition::create([
            'code' => 'COMP-1',
            'name' => 'Algodón',
            'content_text' => '100% algodón',
        ]);

        $entry->content_text = '100% algodón peinado';
        $entry->save();

        $this->assertSame(2, $entry->refresh()->version);
    }

    public function test_la_version_no_sube_si_no_cambia_el_contenido(): void
    {
        $entry = TechnicalSheetFit::create([
            'code' => 'FIT-1',
            'name' => 'Regular',
            'content_text' => 'Corte regular.',
        ]);

        // Un guardado que no toca el contenido (por ejemplo, reactivar) no debe
        // consumir versiones: la versión señala cambios reales de contenido.
        $entry->save();

        $this->assertSame(1, $entry->refresh()->version);
    }

    public function test_la_version_no_se_puede_bajar_escribiendola_a_mano(): void
    {
        $entry = TechnicalSheetComposition::create([
            'code' => 'COMP-1',
            'name' => 'Algodón',
            'content_text' => '100% algodón',
        ]);

        $entry->content_text = 'Algodón peinado';
        $entry->version = 1;
        $entry->save();

        // Escribir la versión a mano no la congela: el sistema la recalcula,
        // porque si no dos contenidos distintos compartirían número.
        $this->assertSame(2, $entry->refresh()->version);
    }

    public function test_el_html_de_la_guia_se_sanitiza_al_guardar(): void
    {
        $guide = TechnicalSheetSizeGuide::create([
            'code' => 'TALLA-1',
            'name' => 'Camiseta',
            'content_html' => '<table onclick="x()"><tbody><tr><td>A<script>alert(1)</script></td></tr></tbody></table>',
        ]);

        $stored = (string) $guide->refresh()->content_html;

        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<table>', $stored);
        $this->assertStringContainsString('<td>A</td>', $stored);
    }

    public function test_las_instrucciones_de_cuidado_se_leen_una_por_linea(): void
    {
        $care = TechnicalSheetCare::create([
            'code' => 'CARE-1',
            'name' => 'Básicos',
            'content_text' => "Lavar del revés a 30 ºC.\n\nNo usar secadora.\n",
        ]);

        $this->assertSame(
            ['Lavar del revés a 30 ºC.', 'No usar secadora.'],
            $care->instructions(),
        );
    }

    public function test_solo_se_ofrecen_los_mantenimientos_activos(): void
    {
        $active = TechnicalSheetComposition::factory()->create(['code' => 'ACTIVA']);
        $inactive = TechnicalSheetComposition::factory()->inactive()->create(['code' => 'INACTIVA']);

        $options = app(TechnicalSheetCatalog::class)->options(
            ProductTechnicalSheet::SLOT_COMPOSITION,
            null,
            null,
        );

        $this->assertArrayHasKey($active->getKey(), $options);
        $this->assertArrayNotHasKey($inactive->getKey(), $options);
    }

    public function test_un_mantenimiento_de_otro_tipo_o_publico_no_se_ofrece(): void
    {
        $generic = TechnicalSheetSizeGuide::factory()->create(['code' => 'GENERICA']);
        $forBags = TechnicalSheetSizeGuide::factory()->forType(ProductType::Bag)->create(['code' => 'BOLSOS']);
        $forKids = TechnicalSheetSizeGuide::factory()->forAudience(Audience::Kids)->create(['code' => 'INFANTIL']);

        $options = app(TechnicalSheetCatalog::class)->options(
            ProductTechnicalSheet::SLOT_SIZE_GUIDE,
            ProductType::Tshirt,
            Audience::Woman,
        );

        $this->assertArrayHasKey($generic->getKey(), $options);
        $this->assertArrayNotHasKey($forBags->getKey(), $options);
        $this->assertArrayNotHasKey($forKids->getKey(), $options);
    }

    public function test_la_seleccion_actual_sigue_siendo_elegible_aunque_se_desactive(): void
    {
        // Si desapareciera de la lista, el guardado automático borraría la
        // selección sin que nadie lo hubiera pedido.
        $entry = TechnicalSheetFit::factory()->inactive()->create(['code' => 'DESACTIVADO']);

        $options = app(TechnicalSheetCatalog::class)->options(
            ProductTechnicalSheet::SLOT_FIT,
            null,
            null,
            includeId: (int) $entry->getKey(),
        );

        $this->assertArrayHasKey($entry->getKey(), $options);
    }

    public function test_un_mantenimiento_de_un_tipo_no_aparece_en_el_selector_de_otro(): void
    {
        // La composición y el ajuste son tablas distintas: el identificador de
        // una no puede resolver el contenido de la otra.
        $composition = TechnicalSheetComposition::factory()->create();

        $this->assertNull(
            app(TechnicalSheetCatalog::class)->previewSelection(
                ProductTechnicalSheet::SLOT_FIT,
                (int) $composition->getKey(),
            ),
        );
    }

    public function test_la_ficha_se_relaciona_con_los_cuatro_mantenimientos(): void
    {
        $product = Product::factory()->create([
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()->create()->getKey(),
            'technical_sheet_fit_id' => TechnicalSheetFit::factory()->create()->getKey(),
            'technical_sheet_care_id' => TechnicalSheetCare::factory()->create()->getKey(),
            'technical_sheet_size_guide_id' => TechnicalSheetSizeGuide::factory()->create()->getKey(),
        ]);

        $this->assertInstanceOf(TechnicalSheetComposition::class, $product->technicalSheetComposition);
        $this->assertInstanceOf(TechnicalSheetFit::class, $product->technicalSheetFit);
        $this->assertInstanceOf(TechnicalSheetCare::class, $product->technicalSheetCare);
        $this->assertInstanceOf(TechnicalSheetSizeGuide::class, $product->technicalSheetSizeGuide);
    }

    public function test_borrar_un_mantenimiento_no_rompe_la_ficha(): void
    {
        $composition = TechnicalSheetComposition::factory()->create();

        $product = Product::factory()->create([
            'technical_sheet_composition_id' => $composition->getKey(),
        ]);

        $composition->delete();

        $this->assertNull($product->refresh()->technical_sheet_composition_id);
    }
}
