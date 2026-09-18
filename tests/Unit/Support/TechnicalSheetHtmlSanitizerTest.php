<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Security\HtmlSanitizer;
use App\Support\Security\TechnicalSheetHtmlSanitizer;
use Tests\UnitTestCase;

/**
 * Whitelist de la guía de tallas (RFC-0008).
 *
 * La lista es propia y **distinta** de la de la descripción comercial: aquí sí
 * se permite `table` y allí no. Estas pruebas fijan las dos, porque ampliar una
 * sola habría debilitado la descripción sin que nada lo detectase.
 */
class TechnicalSheetHtmlSanitizerTest extends UnitTestCase
{
    private TechnicalSheetHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new TechnicalSheetHtmlSanitizer;
    }

    public function test_conserva_la_tabla_completa(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<table><thead><tr><th>Talla</th><th>Pecho</th></tr></thead>'
            .'<tbody><tr><td>S</td><td>96</td></tr><tr><td>M</td><td>102</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<table>', (string) $clean);
        $this->assertStringContainsString('<thead>', (string) $clean);
        $this->assertStringContainsString('<tbody>', (string) $clean);
        $this->assertStringContainsString('<th>Talla</th>', (string) $clean);
        $this->assertStringContainsString('<td>96</td>', (string) $clean);
    }

    public function test_conserva_las_etiquetas_de_texto_de_la_lista(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p>Nota <strong>importante</strong> y <em>énfasis</em></p><br />'
            .'<ul><li>uno</li></ul><ol><li>dos</li></ol>'
        );

        foreach (['<p>', '<strong>', '<em>', '<br', '<ul>', '<ol>', '<li>'] as $tag) {
            $this->assertStringContainsString($tag, (string) $clean);
        }
    }

    public function test_elimina_scripts_y_manejadores_de_eventos(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<table><tbody><tr><td onclick="alert(1)">A<script>alert(2)</script></td></tr></tbody></table>'
        );

        $this->assertStringNotContainsString('script', (string) $clean);
        $this->assertStringNotContainsString('onclick', (string) $clean);
        $this->assertStringContainsString('A', (string) $clean);
    }

    public function test_elimina_iframes_y_hojas_de_estilo(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<table style="color:red"><tbody><tr><td>A<iframe src="x"></iframe></td></tr></tbody></table>'
        );

        $this->assertStringNotContainsString('iframe', (string) $clean);
        $this->assertStringNotContainsString('style', (string) $clean);
    }

    public function test_elimina_los_atributos_de_combinacion_de_celdas(): void
    {
        // Se pierden por diseño: la whitelist del RFC no admite atributos. Lo
        // importante es que la pérdida sea detectable para poder avisar.
        $html = '<table><tbody><tr><td colspan="2">Medidas</td></tr></tbody></table>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('colspan', (string) $clean);
        $this->assertTrue($this->sanitizer->losesTableCellSpans($html));
        $this->assertFalse($this->sanitizer->losesTableCellSpans('<table><tbody><tr><td>A</td></tr></tbody></table>'));
    }

    public function test_conserva_las_celdas_vacias(): void
    {
        // Una celda vacía es información: «esta talla no tiene medida». Si se
        // eliminara, la fila quedaría desalineada respecto a la cabecera.
        $clean = $this->sanitizer->sanitize(
            '<table><tbody><tr><td>S</td><td>96</td></tr><tr><td>M</td><td></td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<td>M</td><td></td>', (string) $clean);
    }

    public function test_respeta_los_acentos(): void
    {
        $clean = $this->sanitizer->sanitize('<table><tbody><tr><td>Niño · años · Ø</td></tr></tbody></table>');

        $this->assertStringContainsString('Niño · años · Ø', (string) $clean);
    }

    public function test_detecta_html_sucio(): void
    {
        $this->assertTrue($this->sanitizer->isClean('<table><tbody><tr><td>A</td></tr></tbody></table>'));
        $this->assertFalse($this->sanitizer->isClean('<table><tbody><tr><td onclick="x()">A</td></tr></tbody></table>'));
    }

    public function test_devuelve_nulo_si_recibe_nulo(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertTrue($this->sanitizer->isClean(null));
    }

    /**
     * La whitelist de la descripción comercial sigue rechazando tablas.
     *
     * Es la comprobación que impide que la ampliación de RFC-0008 se filtre a la
     * descripción: son dos superficies distintas y deben seguir siéndolo.
     */
    public function test_la_descripcion_comercial_sigue_sin_admitir_tablas(): void
    {
        $commercial = (new HtmlSanitizer)->sanitize('<p>Texto</p><table><tr><td>celda</td></tr></table>');

        $this->assertStringContainsString('<p>Texto</p>', (string) $commercial);
        $this->assertStringNotContainsString('<table>', (string) $commercial);
        $this->assertStringNotContainsString('<td>', (string) $commercial);
    }
}
