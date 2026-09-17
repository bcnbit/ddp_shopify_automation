<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Security\HtmlSanitizer;
use Tests\UnitTestCase;

class HtmlSanitizerTest extends UnitTestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_conserva_la_whitelist_del_rfc_0001(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p>Texto <strong>fuerte</strong> y <em>énfasis</em></p>'
            .'<h2>Título</h2><h3>Subtítulo</h3>'
            .'<ul><li>uno</li></ul><ol><li>dos</li></ol><br />'
        );

        $this->assertStringContainsString('<strong>fuerte</strong>', $clean);
        $this->assertStringContainsString('<em>énfasis</em>', $clean);
        $this->assertStringContainsString('<h2>Título</h2>', $clean);
        $this->assertStringContainsString('<h3>Subtítulo</h3>', $clean);
        $this->assertStringContainsString('<ul>', $clean);
        $this->assertStringContainsString('<ol>', $clean);
    }

    public function test_elimina_etiquetas_fuera_de_la_whitelist(): void
    {
        $clean = $this->sanitizer->sanitize('<h1>No permitido</h1><table><tr><td>celda</td></tr></table>');

        $this->assertStringNotContainsString('<h1>', $clean);
        $this->assertStringNotContainsString('<table>', $clean);
        $this->assertStringNotContainsString('<td>', $clean);
    }

    public function test_elimina_scripts_y_manejadores_de_eventos(): void
    {
        $clean = $this->sanitizer->sanitize('<p onclick="alert(1)">Hola<script>alert("xss")</script></p>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('Hola', $clean);
    }

    public function test_elimina_atributos_de_estilo_y_clase(): void
    {
        $clean = $this->sanitizer->sanitize('<p class="x" style="color:red">Texto</p>');

        $this->assertSame('<p>Texto</p>', $clean);
    }

    public function test_elimina_imagenes_y_enlaces(): void
    {
        $clean = $this->sanitizer->sanitize('<img src="x.png"><a href="https://evil.test">enlace</a>');

        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('<a ', $clean);
        $this->assertStringNotContainsString('href', $clean);
    }

    public function test_respeta_los_acentos_y_entidades(): void
    {
        $clean = $this->sanitizer->sanitize('<p>Camiseta de algodón &amp; lino · ñ á é</p>');

        $this->assertStringContainsString('algodón', $clean);
        $this->assertStringContainsString('ñ á é', $clean);
    }

    public function test_devuelve_nulo_si_recibe_nulo(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
    }

    public function test_detecta_html_sucio(): void
    {
        $this->assertFalse($this->sanitizer->isClean('<p onclick="x()">Hola</p>'));
        $this->assertTrue($this->sanitizer->isClean('<p>Hola</p>'));
    }
}
