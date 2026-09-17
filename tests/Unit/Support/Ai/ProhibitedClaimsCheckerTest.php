<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Models\Product;
use App\Support\Ai\ContentProposal;
use App\Support\Ai\ProductFactSheet;
use App\Support\Ai\ProhibitedClaimsChecker;
use Tests\UnitTestCase;

/**
 * Prohibiciones de la IA (RFC-0003).
 *
 * El RFC exige que, ante un dato ausente, el sistema emita una advertencia o no
 * mencione el dato. Estas pruebas fijan qué se considera una afirmación no
 * respaldada y comprueban que la mención legítima no se señala.
 */
class ProhibitedClaimsCheckerTest extends UnitTestCase
{
    private function checker(): ProhibitedClaimsChecker
    {
        return new ProhibitedClaimsChecker;
    }

    private function proposal(string $text): ContentProposal
    {
        return new ContentProposal(
            title: 'Camiseta',
            shortBenefit: null,
            htmlDescription: '<p>'.$text.'</p>',
            seoTitle: 'Camiseta Dies de Platja',
            seoDescription: 'Descripción breve de la camiseta.',
            handleSuggestion: 'camiseta',
        );
    }

    private function factsWithNoComposition(): ProductFactSheet
    {
        return ProductFactSheet::fromProduct(new Product([
            'internal_reference' => 'DDP-1',
            'source_name' => 'Camiseta',
            'composition' => null,
            'fit' => null,
        ]));
    }

    public function test_detecta_algodon_organico_sin_dato_confirmado(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Confeccionada en algodón orgánico de gran calidad.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('algodón orgánico', $warnings[0]);
    }

    public function test_detecta_hecho_en_espana_sin_origen_confirmado(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Una prenda hecha en España con mucho cuidado.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_edicion_limitada(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Edición limitada de esta temporada.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_unisex_sin_publico_confirmado(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Diseño unisex para cualquier persona.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_oversize_sin_ajuste_confirmado(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Corte oversize muy cómodo.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_disponibilidad_y_envios(): void
    {
        $disponibilidad = $this->checker()->inspect(
            $this->proposal('Quedan últimas unidades en stock.'),
            $this->factsWithNoComposition(),
        );

        $envios = $this->checker()->inspect(
            $this->proposal('Envío gratis en 24 horas.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($disponibilidad);
        $this->assertNotEmpty($envios);
    }

    public function test_detecta_rebajas(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Aprovecha esta oferta con descuento.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_medidas_concretas(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Mide 50 cm de ancho y 70 cm de largo.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_detecta_certificaciones(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Tejido certificado de origen sostenible.'),
            $this->factsWithNoComposition(),
        );

        $this->assertNotEmpty($warnings);
    }

    public function test_una_mencion_legitima_no_se_senala_si_el_dato_esta_confirmado(): void
    {
        // La composición SÍ consta, así que mencionarla es correcto.
        $facts = ProductFactSheet::fromProduct(new Product([
            'internal_reference' => 'DDP-2',
            'source_name' => 'Camiseta',
            'composition' => '100% algodón orgánico certificado GOTS',
        ]));

        $warnings = $this->checker()->inspect(
            $this->proposal('Confeccionada en algodón orgánico.'),
            $facts,
        );

        $this->assertSame([], $warnings);
    }

    public function test_el_publico_confirmado_permite_mencionar_unisex(): void
    {
        $facts = ProductFactSheet::fromProduct(new Product([
            'internal_reference' => 'DDP-3',
            'source_name' => 'Camiseta',
            'audience' => 'unisex',
        ]));

        $warnings = $this->checker()->inspect(
            $this->proposal('Modelo unisex de corte recto.'),
            $facts,
        );

        $this->assertSame([], $warnings);
    }

    public function test_un_texto_sin_afirmaciones_no_genera_avisos(): void
    {
        $warnings = $this->checker()->inspect(
            $this->proposal('Camiseta de manga corta con un diseño inspirado en la costa.'),
            $this->factsWithNoComposition(),
        );

        $this->assertSame([], $warnings);
    }

    public function test_has_prohibited_claims_resume_el_resultado(): void
    {
        $this->assertTrue($this->checker()->hasProhibitedClaims(
            $this->proposal('Hecha en España.'),
            $this->factsWithNoComposition(),
        ));

        $this->assertFalse($this->checker()->hasProhibitedClaims(
            $this->proposal('Camiseta de manga corta.'),
            $this->factsWithNoComposition(),
        ));
    }
}
