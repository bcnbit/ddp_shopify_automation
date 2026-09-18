<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Security\SecretRedactor;
use PHPUnit\Framework\TestCase as BaseTestCase;

class SecretRedactorTest extends BaseTestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new SecretRedactor;
    }

    /**
     * La API secret key de la aplicación (`shpss_`) no es un access token, pero es
     * una credencial con la que se firman los intercambios OAuth. Si aparece en un
     * mensaje de error —por ejemplo, porque Shopify la repite en su respuesta— debe
     * quedar enmascarada igual que un token (RFC-0009).
     */
    public function test_enmascara_la_api_secret_key_de_shopify(): void
    {
        // La credencial se sustituye dentro del texto, no se descarta el mensaje:
        // el resto del error sigue siendo útil para diagnosticar.
        $this->assertSame(
            'Falló con '.SecretRedactor::MASK.' al canjear',
            $this->redactor->redactString('Falló con shpss_1234567890abcdef al canjear'),
        );

        // Y no debe quedar ningún rastro del valor original.
        $this->assertStringNotContainsString(
            'shpss_1234567890abcdef',
            (string) $this->redactor->redactString('shpss_1234567890abcdef'),
        );

        // Y también cuando viaja como valor de una clave sensible.
        $result = $this->redactor->redact([
            'client_secret' => 'shpss_1234567890abcdef',
        ]);

        $this->assertSame(SecretRedactor::MASK, $result['client_secret']);
    }

    public function test_enmascara_claves_sensibles(): void
    {
        $result = $this->redactor->redact([
            'password' => 'super-secreta',
            'api_key' => 'sk-abcdefghijklmnopqrstuvwxyz',
            'shopify_access_token' => 'shpat_1234567890abcdef',
            'name' => 'Camiseta',
        ]);

        $this->assertSame(SecretRedactor::MASK, $result['password']);
        $this->assertSame(SecretRedactor::MASK, $result['api_key']);
        $this->assertSame(SecretRedactor::MASK, $result['shopify_access_token']);
        $this->assertSame('Camiseta', $result['name']);
    }

    public function test_enmascara_claves_anidadas(): void
    {
        $result = $this->redactor->redact([
            'connection' => [
                'token' => 'shpat_secreto',
                'domain' => 'tienda.myshopify.com',
            ],
        ]);

        $this->assertSame(SecretRedactor::MASK, $result['connection']['token']);
        $this->assertSame('tienda.myshopify.com', $result['connection']['domain']);
    }

    public function test_no_registra_html_completo_ni_imagenes_en_base64(): void
    {
        $result = $this->redactor->redact([
            'html_description' => '<p>'.str_repeat('texto ', 100).'</p>',
            'image_base64' => str_repeat('A', 5000),
        ]);

        $this->assertStringContainsString(SecretRedactor::MASK, (string) $result['html_description']);
        $this->assertStringNotContainsString('texto texto', (string) $result['html_description']);
        $this->assertStringNotContainsString('AAAA', (string) $result['image_base64']);
    }

    public function test_redacta_patrones_dentro_de_texto_libre(): void
    {
        $this->assertStringNotContainsString(
            'shpat_1234567890abcdef',
            (string) $this->redactor->redactString('Falló con token shpat_1234567890abcdef al llamar'),
        );

        $this->assertStringNotContainsString(
            'abc123',
            (string) $this->redactor->redactString('Authorization: Bearer abc123'),
        );

        $this->assertStringNotContainsString(
            'sk-abcdefghijklmnopqrst',
            (string) $this->redactor->redactString('api_key=sk-abcdefghijklmnopqrst'),
        );
    }

    public function test_conserva_texto_no_sensible(): void
    {
        $message = 'El producto DDP-1001 no tiene precio asignado.';

        $this->assertSame($message, $this->redactor->redactString($message));
    }

    public function test_no_enmascara_datos_de_auditoria_que_solo_suenan_a_secreto(): void
    {
        // `input_tokens` contiene «token» y `prompt_version` contiene «prompt»,
        // pero no son credenciales: enmascararlos dejaría ilegible justo la
        // auditoría de coste que RFC-0003 exige.
        $result = $this->redactor->redact([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'prompt_version' => 'v1',
            'model' => 'openai/gpt-4.1',
            'latency_ms' => 1200,
            'cost_estimate' => 0.0021,
        ]);

        $this->assertSame(100, $result['input_tokens']);
        $this->assertSame(50, $result['output_tokens']);
        $this->assertSame(150, $result['total_tokens']);
        $this->assertSame('v1', $result['prompt_version']);
        $this->assertSame('openai/gpt-4.1', $result['model']);
        $this->assertSame(1200, $result['latency_ms']);
        $this->assertSame(0.0021, $result['cost_estimate']);
    }

    public function test_sigue_enmascarando_las_claves_sensibles_reales(): void
    {
        $result = $this->redactor->redact([
            'api_key' => 'sk-or-v1-secreta',
            'openrouter_api_key' => 'sk-or-v1-secreta',
            'shopify_access_token' => 'shpat_secreta',
            'access_token' => 'secreto',
            'webhook_secret' => 'secreto',
        ]);

        foreach (array_keys($result) as $key) {
            $this->assertSame(SecretRedactor::MASK, $result[$key], "La clave {$key} debería enmascararse.");
        }
    }
}
