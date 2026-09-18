<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\DataObjects\Shopify\ShopifyProductPayload;
use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Services\Products\ProductService;
use App\Support\Products\TechnicalSheetComposer;
use Tests\Support\BuildsSyncableProducts;
use Tests\TestCase;

/**
 * Composición de la descripción enviada a Shopify (RFC-0008).
 *
 * El orden es el que fija la enmienda: descripción comercial, composición,
 * ajuste/tallaje, guía de tallas y cuidados. Cada bloque omitido no deja rastro.
 */
class TechnicalSheetComposerTest extends TestCase
{
    use BuildsSyncableProducts;

    /**
     * Ficha con los cuatro mantenimientos seleccionados y copiados.
     */
    private function productWithFullTechnicalSheet(): Product
    {
        $owner = $this->operadora();

        $product = Product::factory()->create(['created_by' => $owner->getKey()]);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()
                ->create(['content_text' => '100% algodón'])->getKey(),
            'technical_sheet_fit_id' => TechnicalSheetFit::factory()
                ->create(['content_text' => 'Corte regular unisex.'])->getKey(),
            'technical_sheet_care_id' => TechnicalSheetCare::factory()
                ->create(['content_text' => "Lavar a 30 ºC.\nNo usar secadora."])->getKey(),
            'technical_sheet_size_guide_id' => TechnicalSheetSizeGuide::factory()
                ->create([
                    'content_html' => '<table><tbody><tr><td>M</td><td>102</td></tr></tbody></table>',
                    'intro_note' => 'Medidas en centímetros.',
                    'closing_note' => 'Si dudas, elige la mayor.',
                ])->getKey(),
        ], $owner);

        return $product->refresh();
    }

    public function test_los_bloques_aparecen_en_el_orden_del_rfc(): void
    {
        $product = $this->productWithFullTechnicalSheet();

        $html = (new TechnicalSheetComposer)->compose($product, '<p>Camiseta ligera para el día a día.</p>');

        $positions = [
            'descripción' => mb_strpos($html, 'Camiseta ligera para el día a día.'),
            'composición' => mb_strpos($html, '100% algodón'),
            'ajuste' => mb_strpos($html, 'Corte regular unisex.'),
            'guía' => mb_strpos($html, '102'),
            'cuidados' => mb_strpos($html, 'Lavar a 30 ºC.'),
        ];

        foreach ($positions as $label => $position) {
            $this->assertNotFalse($position, "Falta el bloque «{$label}» en la descripción compuesta.");
        }

        $this->assertTrue(
            $positions['descripción'] < $positions['composición']
            && $positions['composición'] < $positions['ajuste']
            && $positions['ajuste'] < $positions['guía']
            && $positions['guía'] < $positions['cuidados'],
            'El orden de los bloques no es descripción, composición, ajuste, guía y cuidados.'
        );
    }

    public function test_los_cuidados_con_varias_instrucciones_se_componen_como_lista(): void
    {
        $product = $this->productWithFullTechnicalSheet();

        $html = (new TechnicalSheetComposer)->compose($product, null);

        $this->assertStringContainsString('<h2>Cuidados</h2><ul><li>Lavar a 30 ºC.</li><li>No usar secadora.</li></ul>', $html);
    }

    public function test_la_guia_de_tallas_incluye_sus_dos_notas(): void
    {
        $product = $this->productWithFullTechnicalSheet();

        $html = (new TechnicalSheetComposer)->compose($product, null);

        $this->assertStringContainsString('Medidas en centímetros.', $html);
        $this->assertStringContainsString('<td>102</td>', $html);
        $this->assertStringContainsString('Si dudas, elige la mayor.', $html);
    }

    public function test_un_bloque_vacio_se_omite(): void
    {
        $owner = $this->operadora();
        $product = Product::factory()->create(['created_by' => $owner->getKey()]);

        // Sólo composición: no debe aparecer ni la guía ni los cuidados.
        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()->create()->getKey(),
        ], $owner);

        $html = (new TechnicalSheetComposer)->compose($product->refresh(), '<p>Descripción.</p>');

        $this->assertStringContainsString('<h2>Composición</h2>', $html);
        $this->assertStringNotContainsString('<h2>Ajuste y tallaje</h2>', $html);
        $this->assertStringNotContainsString('<h2>Guía de tallas</h2>', $html);
        $this->assertStringNotContainsString('<h2>Cuidados</h2>', $html);
    }

    public function test_la_descripcion_comercial_vacia_no_deja_un_bloque_huerfano(): void
    {
        $product = $this->productWithFullTechnicalSheet();

        $html = (new TechnicalSheetComposer)->compose($product, null);

        $this->assertStringStartsWith('<h2>Composición</h2>', $html);
    }

    public function test_el_texto_plano_se_escapa_al_componer(): void
    {
        $owner = $this->operadora();
        $product = Product::factory()->create(['created_by' => $owner->getKey()]);

        app(ProductService::class)->update($product, [
            'technical_sheet_fit_id' => TechnicalSheetFit::factory()
                ->create(['content_text' => 'Corte <script>alert(1)</script> regular'])->getKey(),
        ], $owner);

        $html = (new TechnicalSheetComposer)->compose($product->refresh(), null);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Criterio 7 y enmienda a RFC-0004: la carga útil de Shopify lleva la
     * descripción compuesta, no sólo el texto comercial.
     */
    public function test_la_carga_util_de_shopify_lleva_la_ficha_tecnica_compuesta(): void
    {
        $owner = $this->operadora();
        $product = $this->syncableProduct(owner: $owner);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()
                ->create(['content_text' => '100% algodón'])->getKey(),
            'technical_sheet_size_guide_id' => TechnicalSheetSizeGuide::factory()
                ->create([
                    'content_html' => '<table><tbody><tr><td>M</td><td>102</td></tr></tbody></table>',
                ])->getKey(),
        ], $owner);

        $payload = ShopifyProductPayload::fromProduct($product->refresh());

        $this->assertStringContainsString('Camiseta de corte regular para el día a día.', $payload->descriptionHtml);
        $this->assertStringContainsString('<h2>Composición</h2>', $payload->descriptionHtml);
        $this->assertStringContainsString('100% algodón', $payload->descriptionHtml);
        $this->assertStringContainsString('<h2>Guía de tallas</h2>', $payload->descriptionHtml);
        $this->assertStringContainsString('<td>102</td>', $payload->descriptionHtml);
        $this->assertTrue($payload->isDraft(), 'El estado debe seguir siendo DRAFT.');
    }

    /**
     * Criterio 5: la descripción base para IA no se publica literalmente.
     */
    public function test_la_descripcion_base_para_ia_no_se_publica_en_shopify(): void
    {
        $owner = $this->operadora();
        $product = $this->syncableProduct(owner: $owner);

        $texto = 'Inspirada en las tardes de agosto en la cala, con acabado mate.';

        app(ProductService::class)->update($product, [
            'ai_base_description' => $texto,
        ], $owner);

        $payload = ShopifyProductPayload::fromProduct($product->refresh());

        $this->assertStringNotContainsString($texto, $payload->descriptionHtml);
        $this->assertStringNotContainsString('agosto', $payload->descriptionHtml);
        $this->assertStringNotContainsString($texto, (string) json_encode($payload->toArray()));
    }

    public function test_las_observaciones_internas_no_llegan_a_shopify(): void
    {
        $owner = $this->operadora();
        $product = $this->syncableProduct(owner: $owner);

        app(ProductService::class)->update($product, [
            'notes' => 'Revisar el estampado con Rosa antes de publicar.',
        ], $owner);

        $payload = ShopifyProductPayload::fromProduct($product->refresh());

        $this->assertStringNotContainsString('Rosa', $payload->descriptionHtml);
    }

    /**
     * Criterio 6: una ficha sin mantenimientos envía sólo su descripción.
     *
     * `syncableProduct()` rellena las columnas libres, así que la ficha tampoco
     * tiene de dónde sacar bloques técnicos: el resultado es la descripción
     * comercial sin nada añadido.
     */
    public function test_una_ficha_sin_mantenimientos_envia_solo_la_descripcion_comercial(): void
    {
        $product = $this->syncableProduct();

        $product->update(['composition' => null, 'fit' => null, 'care_instructions' => null]);

        $payload = ShopifyProductPayload::fromProduct($product->refresh());

        $this->assertSame(
            '<p>Camiseta de corte regular para el día a día.</p>',
            $payload->descriptionHtml,
        );
    }

    /**
     * Las fichas anteriores a RFC-0008 conservan sus columnas libres.
     *
     * Es el respaldo de §5.4: sin mantenimiento seleccionado, la composición,
     * el ajuste y los cuidados que una persona ya había confirmado a mano siguen
     * llegando a Shopify en el mismo orden.
     */
    public function test_una_ficha_antigua_sin_mantenimientos_envia_sus_columnas_libres(): void
    {
        $product = $this->syncableProduct();

        $payload = ShopifyProductPayload::fromProduct($product);

        $this->assertStringContainsString('<h2>Composición</h2><p>100% algodón peinado</p>', $payload->descriptionHtml);
        $this->assertStringContainsString('<h2>Ajuste y tallaje</h2><p>Corte regular</p>', $payload->descriptionHtml);
        $this->assertStringContainsString('<h2>Cuidados</h2><p>Lavar a 30 ºC del revés</p>', $payload->descriptionHtml);
    }

    /**
     * La guía de tallas se coloca después del ajuste y antes de los cuidados,
     * que es donde el RFC la sitúa y donde no rompe la lectura.
     */
    public function test_la_guia_se_inserta_entre_el_ajuste_y_los_cuidados(): void
    {
        $owner = $this->operadora();
        $product = Product::factory()->create(['created_by' => $owner->getKey()]);

        app(ProductService::class)->update($product, [
            'technical_sheet_fit_id' => TechnicalSheetFit::factory()->create(['content_text' => 'Corte recto.'])->getKey(),
            'technical_sheet_care_id' => TechnicalSheetCare::factory()->create(['content_text' => 'Lavar a mano.'])->getKey(),
            'technical_sheet_size_guide_id' => TechnicalSheetSizeGuide::factory()->create()->getKey(),
        ], $owner);

        $blocks = array_keys((new TechnicalSheetComposer)->blocks($product->refresh()));

        $this->assertSame(
            [
                ProductTechnicalSheet::SLOT_FIT,
                ProductTechnicalSheet::SLOT_SIZE_GUIDE,
                ProductTechnicalSheet::SLOT_CARE,
            ],
            $blocks,
        );
    }
}
