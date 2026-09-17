<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Exceptions\Ai\AiResponseRejected;
use App\Support\Ai\AiResponseValidator;
use Tests\UnitTestCase;

/**
 * Contrato de salida de la IA (RFC-0003).
 *
 * El RFC exige rechazar la salida que no cumpla el esquema y sanitizar el HTML
 * antes de persistir. Estas pruebas fijan ese contrato.
 */
class AiResponseValidatorTest extends UnitTestCase
{
    private function validator(): AiResponseValidator
    {
        return app(AiResponseValidator::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Camiseta Dies de Platja de algodón',
            'short_benefit' => 'Suave para el día a día.',
            'html_description' => '<p>Camiseta de corte regular.</p><ul><li>Tejido agradable</li></ul>',
            'seo_title' => 'Camiseta Dies de Platja de algodón para el día a día',
            'seo_description' => str_repeat('Descripción de la camiseta de algodón Dies de Platja. ', 3),
            'handle_suggestion' => 'Camiseta Dies de Platja',
            'tags' => ['camiseta', 'algodón', 'verano', 'camiseta', ''],
            'alt_texts' => [['media_id' => 7, 'text' => 'Camiseta blanca vista de frente']],
            'facts_detected' => ['tipo: camiseta'],
            'warnings' => [],
        ];
    }

    public function test_acepta_una_respuesta_completa(): void
    {
        $proposal = $this->validator()->validate($this->validPayload());

        $this->assertSame('Camiseta Dies de Platja de algodón', $proposal->title);
        $this->assertStringContainsString('<p>', $proposal->htmlDescription);
    }

    public function test_rechaza_una_respuesta_sin_titulo(): void
    {
        $payload = $this->validPayload();
        unset($payload['title']);

        $this->expectException(AiResponseRejected::class);

        $this->validator()->validate($payload);
    }

    public function test_rechaza_una_respuesta_sin_descripcion(): void
    {
        $payload = $this->validPayload();
        $payload['html_description'] = '';

        $this->expectException(AiResponseRejected::class);

        $this->validator()->validate($payload);
    }

    public function test_rechaza_una_respuesta_sin_seo(): void
    {
        $payload = $this->validPayload();
        $payload['seo_title'] = null;
        $payload['seo_description'] = null;

        $this->expectException(AiResponseRejected::class);

        $this->validator()->validate($payload);
    }

    public function test_rechaza_si_la_descripcion_queda_vacia_al_limpiar_el_html(): void
    {
        $payload = $this->validPayload();
        $payload['html_description'] = '<script>alert(1)</script>';

        $this->expectException(AiResponseRejected::class);

        $this->validator()->validate($payload);
    }

    public function test_limpia_el_html_de_la_descripcion(): void
    {
        $payload = $this->validPayload();
        $payload['html_description'] = '<p>Texto</p><h1>No</h1><script>alert(1)</script><div>div</div>';

        $proposal = $this->validator()->validate($payload);

        $this->assertStringNotContainsString('<script>', $proposal->htmlDescription);
        $this->assertStringNotContainsString('<h1>', $proposal->htmlDescription);
        $this->assertStringNotContainsString('<div>', $proposal->htmlDescription);
        $this->assertStringContainsString('<p>Texto</p>', $proposal->htmlDescription);
    }

    public function test_normaliza_el_handle(): void
    {
        $payload = $this->validPayload();
        $payload['handle_suggestion'] = '  Camiseta Dies de Platja 2026!! ';

        $proposal = $this->validator()->validate($payload);

        $this->assertSame('camiseta-dies-de-platja-2026', $proposal->handleSuggestion);
    }

    public function test_normaliza_las_etiquetas_quitando_duplicados_y_vacias(): void
    {
        $proposal = $this->validator()->validate($this->validPayload());

        $this->assertSame(['camiseta', 'algodón', 'verano'], $proposal->tags);
    }

    public function test_limita_el_numero_de_etiquetas(): void
    {
        $payload = $this->validPayload();
        $payload['tags'] = array_map(static fn (int $i): string => "etiqueta-{$i}", range(1, 40));

        $proposal = $this->validator()->validate($payload);

        $this->assertCount((int) config('product-studio.content.tags_max'), $proposal->tags);
    }

    public function test_descarta_alt_sin_identificador_o_sin_texto(): void
    {
        $payload = $this->validPayload();
        $payload['alt_texts'] = [
            ['media_id' => 1, 'text' => 'Válido'],
            ['media_id' => null, 'text' => 'Sin id'],
            ['media_id' => 2, 'text' => ''],
            'no es un array',
        ];

        $proposal = $this->validator()->validate($payload);

        $this->assertCount(1, $proposal->altTexts);
        $this->assertSame(1, $proposal->altTexts[0]['media_id']);
    }

    public function test_decode_acepta_json_envuelto_en_bloque_de_codigo(): void
    {
        $decoded = $this->validator()->decode("```json\n{\"title\": \"Ejemplo\"}\n```");

        $this->assertSame('Ejemplo', $decoded['title']);
    }

    public function test_decode_rechaza_texto_libre(): void
    {
        $this->expectException(AiResponseRejected::class);

        $this->validator()->decode('Aquí tienes tu descripción: una camiseta muy bonita.');
    }

    public function test_decode_rechaza_json_invalido(): void
    {
        $this->expectException(AiResponseRejected::class);

        $this->validator()->decode('{"title": "sin cerrar"');
    }
}
