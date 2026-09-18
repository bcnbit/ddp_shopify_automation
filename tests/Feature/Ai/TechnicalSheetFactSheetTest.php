<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\DataObjects\Ai\ContentGenerationRequest;
use App\Enums\Locale;
use App\Models\Product;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Services\Products\ProductService;
use App\Support\Ai\ContentProfile;
use App\Support\Ai\ProductFactSheet;
use App\Support\Ai\PromptBuilder;
use Tests\TestCase;

/**
 * Integración de los mantenimientos con la IA (RFC-0008, enmienda a RFC-0003).
 *
 * La IA debe recibir los datos técnicos confirmados —incluida la guía de tallas
 * y la descripción base— sin que ninguno de ellos se convierta en una licencia
 * para inventar lo que no consta.
 */
class TechnicalSheetFactSheetTest extends TestCase
{
    private function productWithMaintenance(): Product
    {
        $owner = $this->operadora();
        $product = Product::factory()->create([
            'created_by' => $owner->getKey(),
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        app(ProductService::class)->update($product, [
            'technical_sheet_composition_id' => TechnicalSheetComposition::factory()
                ->create(['content_text' => '100% algodón'])->getKey(),
            'technical_sheet_fit_id' => TechnicalSheetFit::factory()
                ->create(['content_text' => 'Unisex regular.'])->getKey(),
            'technical_sheet_care_id' => TechnicalSheetCare::factory()
                ->create(['content_text' => "Lavar del revés a 30 ºC.\nNo usar secadora."])->getKey(),
            'technical_sheet_size_guide_id' => TechnicalSheetSizeGuide::factory()
                ->create([
                    'content_html' => '<table><thead><tr><th>Talla</th><th>Pecho</th></tr></thead>'
                        .'<tbody><tr><td>M</td><td>102</td></tr></tbody></table>',
                    'intro_note' => 'Medidas en centímetros.',
                    'closing_note' => 'Elige la mayor si dudas.',
                ])->getKey(),
        ], $owner);

        return $product->refresh();
    }

    public function test_los_datos_tecnicos_confirmados_viajan_a_la_ia(): void
    {
        $facts = ProductFactSheet::fromProduct($this->productWithMaintenance());

        $this->assertSame('100% algodón', $facts->composition);
        $this->assertSame('Unisex regular.', $facts->fit);
        $this->assertStringContainsString('No usar secadora.', (string) $facts->careInstructions);
        $this->assertTrue($facts->hasComposition());
    }

    public function test_la_guia_de_tallas_viaja_como_texto_legible(): void
    {
        $facts = ProductFactSheet::fromProduct($this->productWithMaintenance());

        $this->assertTrue($facts->hasSizeGuide());

        // El modelo no necesita el marcado de la tabla, pero sí poder leer el
        // dato: la etiqueta desaparece y las celdas quedan separadas.
        $this->assertStringNotContainsString('<td>', (string) $facts->sizeGuide);
        $this->assertStringContainsString('Medidas en centímetros.', (string) $facts->sizeGuide);
        $this->assertStringContainsString('102', (string) $facts->sizeGuide);
        $this->assertStringContainsString('Elige la mayor si dudas.', (string) $facts->sizeGuide);
    }

    public function test_el_corte_confirmado_permite_mencionar_unisex(): void
    {
        // Con el ajuste confirmado, el comprobador de prohibiciones ya no señala
        // «unisex»: el dato está respaldado.
        $facts = ProductFactSheet::fromProduct($this->productWithMaintenance());

        $this->assertSame('Unisex regular.', $facts->fit);
        $this->assertNotContains('ajuste o tallaje', $facts->missingFacts());
    }

    public function test_una_ficha_sin_mantenimiento_sigue_declarando_lo_ausente(): void
    {
        $product = Product::factory()->create([
            'composition' => null,
            'fit' => null,
            'care_instructions' => null,
        ]);

        $facts = ProductFactSheet::fromProduct($product);

        $this->assertNull($facts->composition);
        $this->assertContains('composición', $facts->missingFacts());
        $this->assertFalse($facts->hasSizeGuide());
    }

    /**
     * Criterio 5: la descripción base llega a la IA como contexto.
     */
    public function test_la_descripcion_base_llega_al_prompt_como_contexto(): void
    {
        $owner = $this->operadora();

        $product = Product::factory()->create([
            'created_by' => $owner->getKey(),
            'ai_base_description' => 'Inspirada en las tardes de agosto en la cala.',
        ]);

        $facts = ProductFactSheet::fromProduct($product);

        $this->assertTrue($facts->hasAiBaseDescription());
        $this->assertSame(
            'Inspirada en las tardes de agosto en la cala.',
            $facts->toArray()['descripcion_base_para_ia'] ?? null,
        );

        $request = new ContentGenerationRequest(
            facts: $facts,
            profile: ContentProfile::forProductType($product->product_type),
            locale: Locale::Es,
        );

        $prompt = (new PromptBuilder)->systemPrompt($request);

        $this->assertStringContainsString('CONTEXTO COMERCIAL APORTADO POR LA MARCA', $prompt);
        $this->assertStringContainsString('NO texto publicable', $prompt);
        // Y el texto en sí viaja en el mensaje de usuario, no en las reglas.
        $this->assertStringContainsString(
            'agosto en la cala',
            (new PromptBuilder)->userPrompt($request),
        );
    }

    public function test_sin_descripcion_base_no_se_anade_la_instruccion(): void
    {
        $product = Product::factory()->create(['ai_base_description' => null]);

        $request = new ContentGenerationRequest(
            facts: ProductFactSheet::fromProduct($product),
            profile: ContentProfile::forProductType($product->product_type),
            locale: Locale::Es,
        );

        $this->assertStringNotContainsString(
            'CONTEXTO COMERCIAL APORTADO POR LA MARCA',
            (new PromptBuilder)->systemPrompt($request),
        );
    }

    public function test_la_version_del_prompt_sube_al_cambiar_el_contrato_de_entrada(): void
    {
        // RFC-0008 §6: el contrato de entrada cambia, así que la versión sube.
        // RFC-0003 exige registrar la versión usada en cada generación.
        $this->assertSame('v2', PromptBuilder::VERSION);
    }

    public function test_la_descripcion_base_no_se_publica_pero_si_es_contexto(): void
    {
        // Es la distinción que pide el requerimiento: contexto para la IA,
        // nunca texto literal de la tienda.
        $owner = $this->operadora();

        $product = Product::factory()->create([
            'created_by' => $owner->getKey(),
            'ai_base_description' => 'Acabado mate y tacto suave.',
        ]);

        $facts = ProductFactSheet::fromProduct($product);

        $this->assertTrue($facts->hasAiBaseDescription());
        $this->assertContains('Acabado mate y tacto suave.', $facts->toArray());
    }
}
