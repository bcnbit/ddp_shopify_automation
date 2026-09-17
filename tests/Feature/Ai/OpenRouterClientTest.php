<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AiClient;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Enums\Locale;
use App\Exceptions\Ai\AiRequestFailed;
use App\Models\Product;
use App\Services\Ai\NullAiClient;
use App\Services\Ai\OpenRouterClient;
use App\Support\Ai\ContentProfile;
use App\Support\Ai\ProductFactSheet;
use Illuminate\Support\Facades\Http;
use Tests\UnitTestCase;

/**
 * Cliente de OpenRouter (RFC-0003).
 *
 * Se usa un doble de HTTP: ninguna prueba toca la red real ni consume saldo.
 * Lo que se comprueba es el contrato —petición bien formada, traducción de
 * errores y trazabilidad— no el proveedor.
 */
class OpenRouterClientTest extends UnitTestCase
{
    private function client(): AiClient
    {
        return app(OpenRouterClient::class);
    }

    private function request(): ContentGenerationRequest
    {
        $product = new Product([
            'internal_reference' => 'DDP-1001',
            'source_name' => 'Camiseta Marina',
            'composition' => '100% algodón',
            'price' => 29.90,
        ]);

        return new ContentGenerationRequest(
            facts: ProductFactSheet::fromProduct($product),
            profile: ContentProfile::generic(),
            locale: Locale::Es,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function successBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen-abc123',
            'model' => 'openai/gpt-4.1',
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'title' => 'Camiseta Marina de algodón',
                        'short_benefit' => 'Suave para el día a día.',
                        'html_description' => '<p>Camiseta de corte regular.</p>',
                        'seo_title' => 'Camiseta Marina de algodón Dies de Platja',
                        'seo_description' => str_repeat('Camiseta Marina de algodón para el día a día. ', 3),
                        'handle_suggestion' => 'camiseta-marina',
                        'tags' => ['camiseta', 'algodón'],
                        'alt_texts' => [],
                        'facts_detected' => [],
                        'warnings' => [],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 80],
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('product-studio.ai.driver', 'openrouter');
        config()->set('product-studio.ai.api_key', 'sk-or-v1-clave-de-prueba');
        config()->set('product-studio.ai.base_url', 'https://openrouter.ai/api/v1');
        config()->set('product-studio.ai.model', 'openai/gpt-4.1');
        // Sin reintentos en las pruebas: se comprueba la traducción del error,
        // no la espera del backoff, que alargaría la suite sin aportar nada.
        config()->set('product-studio.ai.retry_times', 0);
    }

    public function test_genera_una_propuesta_a_partir_de_una_respuesta_correcta(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);

        $result = $this->client()->generate($this->request());

        $this->assertSame('Camiseta Marina de algodón', $result->proposal->title);
        $this->assertSame('openai/gpt-4.1', $result->model);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(80, $result->outputTokens);
        $this->assertSame(200, $result->totalTokens());
        $this->assertNotNull($result->latencyMs);
        $this->assertSame('gen-abc123', $result->requestId);
    }

    public function test_envia_la_clave_como_cabecera_y_no_en_el_cuerpo(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);

        $this->client()->generate($this->request());

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer sk-or-v1-clave-de-prueba')
                && ! str_contains($request->body(), 'sk-or-v1-clave-de-prueba');
        });
    }

    public function test_pide_json_estricto_y_el_modelo_configurado(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);

        $this->client()->generate($this->request());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['model'] ?? null) === 'openai/gpt-4.1'
                && ($body['response_format']['type'] ?? null) === 'json_object'
                && is_array($body['messages'] ?? null);
        });
    }

    public function test_el_prompt_incluye_los_datos_confirmados(): void
    {
        Http::fake(['*' => Http::response($this->successBody())]);

        $this->client()->generate($this->request());

        Http::assertSent(function ($request): bool {
            // Se inspecciona el cuerpo decodificado: el transporte escapa los
            // caracteres no ASCII, así que buscar el texto literal en el cuerpo
            // crudo daría un falso negativo.
            $body = $request->data();

            $serialized = json_encode($body, JSON_UNESCAPED_UNICODE);

            return str_contains((string) $serialized, 'DDP-1001')
                && str_contains((string) $serialized, '100% algodón')
                && str_contains((string) $serialized, 'datos_confirmados');
        });
    }

    public function test_traduce_una_clave_invalida_como_error_definitivo(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Debería haber lanzado AiRequestFailed.');
        } catch (AiRequestFailed $failure) {
            $this->assertFalse($failure->isRetryable, 'Una clave inválida no debe reintentarse.');
            $this->assertSame('unauthorized', $failure->errorCode);
        }
    }

    public function test_traduce_el_limite_de_peticiones_como_transitorio(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Debería haber lanzado AiRequestFailed.');
        } catch (AiRequestFailed $failure) {
            $this->assertTrue($failure->isRetryable, 'Un límite de peticiones debe reintentarse.');
            $this->assertSame('rate_limited', $failure->errorCode);
        }
    }

    public function test_traduce_un_error_del_proveedor_como_transitorio(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Upstream error']], 503)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Debería haber lanzado AiRequestFailed.');
        } catch (AiRequestFailed $failure) {
            $this->assertTrue($failure->isRetryable);
            $this->assertSame('provider_unavailable', $failure->errorCode);
        }
    }

    public function test_rechaza_una_respuesta_que_no_cumple_el_contrato(): void
    {
        Http::fake(['*' => Http::response($this->successBody([
            'choices' => [['message' => ['content' => '{"title": "Sólo título"}']]],
        ]))]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Debería haber lanzado AiRequestFailed.');
        } catch (AiRequestFailed $failure) {
            $this->assertFalse($failure->isRetryable, 'Una respuesta inválida no se arregla reintentando.');
            $this->assertSame('invalid_response', $failure->errorCode);
        }
    }

    public function test_rechaza_texto_libre_del_proveedor(): void
    {
        Http::fake(['*' => Http::response($this->successBody([
            'choices' => [['message' => ['content' => 'Claro, aquí tienes la descripción...']]],
        ]))]);

        $this->expectException(AiRequestFailed::class);

        $this->client()->generate($this->request());
    }

    public function test_falla_si_no_hay_clave_configurada(): void
    {
        config()->set('product-studio.ai.api_key', '');
        Http::fake();

        try {
            $this->client()->generate($this->request());
            $this->fail('Debería haber lanzado AiRequestFailed.');
        } catch (AiRequestFailed $failure) {
            $this->assertSame('missing_api_key', $failure->errorCode);
            $this->assertFalse($failure->isRetryable);
        }

        Http::assertNothingSent();
    }

    public function test_el_cliente_nulo_falla_de_forma_explicita(): void
    {
        $this->expectException(AiRequestFailed::class);

        app(NullAiClient::class)->generate($this->request());
    }

    public function test_la_interfaz_se_resuelve_al_driver_configurado(): void
    {
        config()->set('product-studio.ai.driver', 'openrouter');
        $this->assertInstanceOf(OpenRouterClient::class, app(AiClient::class));

        // Un valor desconocido cae al driver nulo en lugar de fallar al arrancar.
        config()->set('product-studio.ai.driver', 'inexistente');
        $this->app->forgetInstance(AiClient::class);
        $this->assertInstanceOf(NullAiClient::class, app(AiClient::class));
    }
}
