<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Models\User;
use App\Services\Products\ProductService;
use App\Services\Products\TechnicalSheetSnapshotService;
use App\Support\Ai\ProductFactSheet;
use App\Support\Products\TechnicalSheetComposer;
use App\Support\Products\TechnicalSheetSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Copia congelada de los mantenimientos (RFC-0008).
 *
 * Es la prueba del criterio central de la enmienda: **modificar un mantenimiento
 * no puede alterar una ficha ya creada, aprobada o sincronizada**. Lo que se
 * envía sale de `product_technical_sheets`, nunca del maestro.
 */
class TechnicalSheetSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function product(User $owner): Product
    {
        return Product::factory()->create(['created_by' => $owner->getKey()]);
    }

    public function test_al_guardar_la_ficha_se_copian_los_mantenimientos(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);
        $fit = TechnicalSheetFit::factory()->create(['content_text' => 'Corte regular.']);
        $care = TechnicalSheetCare::factory()->create(['content_text' => 'Lavar a 30 ºC.']);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
            'technical_sheet_fit_id' => $fit->getKey(),
            'technical_sheet_care_id' => $care->getKey(),
        ], $owner);

        $this->assertSame(3, $product->refresh()->technicalSheets()->count());

        $this->assertDatabaseHas('product_technical_sheets', [
            'product_id' => $product->getKey(),
            'slot' => 'composition',
            'entry_code' => $composition->code,
            'entry_version' => 1,
            'content_text' => '100% algodón',
        ]);
    }

    public function test_modificar_el_mantenimiento_no_altera_la_copia_de_la_ficha(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create([
            'code' => 'COMP-ALG',
            'content_text' => '100% algodón',
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        // Cambia el maestro: sube de versión y el texto es otro.
        $composition->content_text = '100% poliéster';
        $composition->save();

        $this->assertSame(2, $composition->refresh()->version);

        // La ficha sigue enviando el texto congelado y la versión que copió.
        $selection = TechnicalSheetSelection::forProduct($product->refresh());

        $this->assertSame('100% algodón', $selection->effectiveComposition());

        $snapshot = $product->technicalSheet(ProductTechnicalSheet::SLOT_COMPOSITION);

        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->entry_version);
        $this->assertSame('COMP-ALG', $snapshot->entry_code);
    }

    public function test_la_descripcion_enviada_no_cambia_al_editar_el_mantenimiento(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);
        $composer = new TechnicalSheetComposer;

        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        $before = $composer->compose($product->refresh(), '<p>Camiseta ligera.</p>');

        $composition->content_text = '100% poliéster';
        $composition->save();

        $after = $composer->compose($product->refresh(), '<p>Camiseta ligera.</p>');

        $this->assertSame($before, $after);
        $this->assertStringContainsString('100% algodón', $after);
        $this->assertStringNotContainsString('100% poliéster', $after);
    }

    public function test_una_copia_sobrevive_al_borrado_del_mantenimiento(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        $composition->delete();

        $product = $product->refresh();

        // La clave se libera, pero el texto congelado sigue disponible: es lo que
        // permite que una ficha antigua siga enviando su composición.
        $this->assertNull($product->technical_sheet_composition_id);
        $this->assertSame('100% algodón', TechnicalSheetSelection::forProduct($product)->effectiveComposition());
    }

    public function test_retirar_la_seleccion_borra_la_copia(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create();

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => null,
        ], $owner);

        // Si la copia sobreviviera, la ficha seguiría enviando una composición
        // que la persona ya había quitado de la pantalla.
        $this->assertSame(0, $product->refresh()->technicalSheets()->count());
        $this->assertNull(TechnicalSheetSelection::forProduct($product)->effectiveComposition());
    }

    public function test_cambiar_de_mantenimiento_actualiza_la_copia(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $algodon = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);
        $lino = TechnicalSheetComposition::factory()->create(['content_text' => '100% lino']);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $algodon->getKey(),
        ], $owner);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $lino->getKey(),
        ], $owner);

        // Sigue habiendo una sola copia por hueco: se reemplaza, no se acumula.
        $this->assertSame(1, $product->refresh()->technicalSheets()->count());
        $this->assertSame('100% lino', TechnicalSheetSelection::forProduct($product)->effectiveComposition());
    }

    public function test_el_guardado_automatico_repetido_no_acumula_copias(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create();

        $service = app(ProductService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->update($product, [
                'technical_sheet_composition_id' => $composition->getKey(),
                'collection_context' => "Campaña {$i}",
            ], $owner);
        }

        $this->assertSame(1, $product->refresh()->technicalSheets()->count());
    }

    public function test_la_copia_tambien_se_hace_al_crear_la_ficha(): void
    {
        $owner = $this->operadora();
        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);

        $product = app(ProductService::class)->create([
            'internal_reference' => 'DDP-NUEVA',
            'source_name' => 'Camiseta nueva',
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        $this->assertSame(
            '100% algodón',
            TechnicalSheetSelection::forProduct($product)->effectiveComposition(),
        );
    }

    /**
     * La captura no depende de pasar por el servicio de fichas.
     *
     * Es la diferencia entre una garantía y una costumbre: si sólo capturara el
     * servicio, una ficha creada por un seeder, una fábrica o una futura
     * importación quedaría sin copia y **sí** cambiaría al editar el maestro.
     */
    public function test_la_copia_se_captura_aunque_la_ficha_no_pase_por_el_servicio(): void
    {
        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% algodón']);

        // Creación directa por el modelo, sin servicio: es lo que hacen los
        // seeders y las fábricas.
        $product = Product::factory()->create([
            'technical_sheet_composition_id' => $composition->getKey(),
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        $this->assertSame(1, $product->refresh()->technicalSheets()->count());

        $composition->content_text = '100% poliéster';
        $composition->save();

        $this->assertSame(
            '100% algodón',
            TechnicalSheetSelection::forProduct($product->refresh())->effectiveComposition(),
        );
    }

    public function test_una_ficha_antigua_sin_mantenimiento_sigue_usando_sus_columnas_libres(): void
    {
        // Respaldo de RFC-0008 §5.4: las fichas creadas antes de esta enmienda no
        // se quedan sin composición.
        $product = Product::factory()->withConfirmedFacts()->create();

        $selection = TechnicalSheetSelection::forProduct($product);

        $this->assertSame('100% algodón peinado', $selection->effectiveComposition());
        $this->assertSame('Corte regular', $selection->effectiveFit());
        $this->assertSame('Lavar a 30 ºC del revés', $selection->effectiveCare());
        $this->assertNull($selection->composition);
    }

    public function test_el_mantenimiento_tiene_prioridad_sobre_la_columna_libre(): void
    {
        $owner = $this->operadora();
        $product = Product::factory()->withConfirmedFacts()->create(['created_by' => $owner->getKey()]);

        $composition = TechnicalSheetComposition::factory()->create(['content_text' => '100% lino']);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        $selection = TechnicalSheetSelection::forProduct($product->refresh());

        $this->assertSame('100% lino', $selection->effectiveComposition());
        $this->assertSame('100% algodón peinado', $selection->legacyComposition);
    }

    public function test_la_copia_incluye_la_guia_de_tallas_completa(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $guide = TechnicalSheetSizeGuide::factory()->create([
            'content_html' => '<table><tbody><tr><td>S</td><td>96</td></tr></tbody></table>',
            'intro_note' => 'Medidas en centímetros.',
            'closing_note' => 'Elige la talla mayor si dudas.',
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_size_guide_id' => $guide->getKey(),
        ], $owner);

        $snapshot = $product->refresh()->technicalSheet(ProductTechnicalSheet::SLOT_SIZE_GUIDE);

        $this->assertNotNull($snapshot);
        $this->assertStringContainsString('<td>96</td>', (string) $snapshot->content_html);
        $this->assertSame('Medidas en centímetros.', $snapshot->intro_note);
        $this->assertSame('Elige la talla mayor si dudas.', $snapshot->closing_note);
    }

    public function test_el_servicio_de_captura_detecta_una_copia_desactualizada(): void
    {
        $owner = $this->operadora();
        $product = $this->product($owner);

        $composition = TechnicalSheetComposition::factory()->create();

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => $composition->getKey(),
        ], $owner);

        $snapshot = $product->refresh()->technicalSheet(ProductTechnicalSheet::SLOT_COMPOSITION);
        $service = app(TechnicalSheetSnapshotService::class);

        $this->assertFalse($service->isOutdated($snapshot));

        $composition->content_text = 'Otro texto';
        $composition->save();

        $this->assertTrue($service->isOutdated($snapshot->refresh()));
    }

    /**
     * Criterio 6: una ficha sin ningún mantenimiento sigue funcionando.
     */
    public function test_una_ficha_sin_mantenimientos_se_compone_sin_bloques(): void
    {
        $product = Product::factory()->create();

        $composer = new TechnicalSheetComposer;

        $this->assertSame([], $composer->blocks($product));
        $this->assertSame('<p>Camiseta ligera.</p>', $composer->compose($product, '<p>Camiseta ligera.</p>'));
        $this->assertSame('', $composer->compose($product, null));
    }

    /**
     * Criterio 6: y sigue llegando a la IA sin datos técnicos inventados.
     */
    public function test_una_ficha_sin_mantenimientos_declara_los_datos_ausentes(): void
    {
        $product = Product::factory()->create([
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        $facts = ProductFactSheet::fromProduct($product);

        $this->assertFalse($facts->hasComposition());
        $this->assertNull($facts->sizeGuide);
        $this->assertContains('composición', $facts->missingFacts());
        $this->assertContains('ajuste o tallaje', $facts->missingFacts());
    }
}
