<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AiClient;
use App\DataObjects\Ai\AiGenerationResult;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Enums\ProductStatus;
use App\Exceptions\Ai\AiRequestFailed;
use App\Jobs\GenerateProductContentJob;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Services\Ai\ProductGenerationService;
use App\Support\Ai\ContentProposal;
use App\Support\Ai\GenerationLimiter;
use App\Support\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Generación de contenido (RFC-0003).
 *
 * Se sustituye el cliente de IA por un doble para que las pruebas sean
 * deterministas y no dependan de la red ni consuman saldo.
 */
class ProductGenerationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Doble del cliente que devuelve una propuesta fija o un fallo.
     */
    private function fakeClient(?ContentProposal $proposal = null, ?AiRequestFailed $failure = null): void
    {
        $client = new class($proposal, $failure) implements AiClient
        {
            /** @var list<ContentGenerationRequest> */
            public array $received = [];

            public function __construct(
                private readonly ?ContentProposal $proposal,
                private readonly ?AiRequestFailed $failure,
            ) {}

            public function model(): string
            {
                return 'openai/gpt-4.1';
            }

            public function generate(ContentGenerationRequest $request): AiGenerationResult
            {
                $this->received[] = $request;

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new AiGenerationResult(
                    proposal: $this->proposal ?? new ContentProposal(
                        title: 'Camiseta Marina',
                        shortBenefit: 'Suave para el día a día.',
                        htmlDescription: '<p>Camiseta de corte regular.</p>',
                        seoTitle: 'Camiseta Marina de algodón Dies de Platja',
                        seoDescription: str_repeat('Camiseta Marina de algodón para el día a día. ', 3),
                        handleSuggestion: 'camiseta-marina',
                        tags: ['camiseta', 'algodón', 'verano'],
                    ),
                    model: 'openai/gpt-4.1',
                    // Se lee la constante en lugar de escribir la versión: si el
                    // contrato de entrada cambia y la versión sube, esta prueba debe
                    // seguir comprobando que se persiste la versión real, no una cifra.
                    promptVersion: PromptBuilder::VERSION,
                    inputTokens: 100,
                    outputTokens: 50,
                    latencyMs: 1200,
                );
            }
        };

        $this->app->instance(AiClient::class, $client);
    }

    private function product(): Product
    {
        $operadora = $this->operadora();

        return Product::factory()->withConfirmedFacts()->create([
            'created_by' => $operadora->getKey(),
            'price' => 29.90,
        ]);
    }

    public function test_solicitar_generacion_marca_la_ficha_y_encola_el_trabajo(): void
    {
        Queue::fake();
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductGenerationService::class)->request($product, $operadora);

        $this->assertSame(ProductStatus::Generating, $product->refresh()->status);
        Queue::assertPushed(GenerateProductContentJob::class, fn ($job): bool => $job->productId === $product->getKey());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'generation_requested',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_la_generacion_guarda_la_propuesta_y_vuelve_a_revision(): void
    {
        $this->fakeClient();
        $product = $this->product();
        $product->status = ProductStatus::Generating;
        $product->save();

        app(ProductGenerationService::class)->generate($product->getKey());

        $content = $product->refresh()->contentFor();

        $this->assertNotNull($content);
        $this->assertSame('Camiseta Marina', $content->title);
        $this->assertSame('openai/gpt-4.1', $content->ai_model);
        $this->assertSame(PromptBuilder::VERSION, $content->prompt_version);
        $this->assertNotNull($content->generated_at);
        $this->assertSame(ProductStatus::Review, $product->status);
    }

    public function test_registra_modelo_tokens_y_latencia_en_auditoria(): void
    {
        $this->fakeClient();
        $product = $this->product();

        app(ProductGenerationService::class)->generate($product->getKey());

        $log = ActivityLog::where('event', 'generation_succeeded')->firstOrFail();

        $this->assertSame('openai/gpt-4.1', $log->properties['model']);
        $this->assertSame(100, $log->properties['input_tokens']);
        $this->assertSame(50, $log->properties['output_tokens']);
        $this->assertSame(1200, $log->properties['latency_ms']);
    }

    public function test_la_auditoria_de_generacion_no_se_atribuye_a_una_persona(): void
    {
        // Un trabajo en cola no lo ejecuta nadie: atribuirlo a quien inició
        // sesión sería engañoso en la auditoría.
        $this->fakeClient();
        $operadora = $this->operadora();
        $this->actingAs($operadora);
        $product = $this->product();

        app(ProductGenerationService::class)->generate($product->getKey());

        $log = ActivityLog::where('event', 'generation_succeeded')->firstOrFail();

        $this->assertNull($log->user_id);
    }

    public function test_envia_solo_los_datos_confirmados_y_marca_los_ausentes(): void
    {
        $this->fakeClient();
        $product = Product::factory()->create([
            'source_name' => 'Camiseta Marina',
            'composition' => null,
            'fit' => null,
        ]);

        app(ProductGenerationService::class)->generate($product->getKey());

        $client = app(AiClient::class);
        $request = $client->received[0];

        $this->assertFalse($request->facts->hasComposition());
        $this->assertContains('composición', $request->facts->missingFacts());
        $this->assertArrayNotHasKey('composicion', $request->facts->toArray());
    }

    public function test_adjunta_hasta_cuatro_imagenes_redimensionadas(): void
    {
        $this->fakeClient();
        $product = $this->product();

        foreach (range(1, 6) as $index) {
            ProductMedia::factory()->forProduct($product)->create([
                'sort_order' => $index,
                'width' => 2000,
                'height' => 2000,
            ]);
        }

        app(ProductGenerationService::class)->generate($product->refresh()->getKey());

        $request = app(AiClient::class)->received[0];

        $this->assertLessThanOrEqual(4, count($request->imageDataUris));
    }

    public function test_una_afirmacion_no_respaldada_se_convierte_en_advertencia(): void
    {
        // La composición NO consta, así que mencionar algodón orgánico es una
        // invención que debe llegar a la persona como advertencia.
        $this->fakeClient(new ContentProposal(
            title: 'Camiseta',
            shortBenefit: null,
            htmlDescription: '<p>Confeccionada en algodón orgánico de gran calidad.</p>',
            seoTitle: 'Camiseta Dies de Platja',
            seoDescription: 'Camiseta de algodón.',
            handleSuggestion: 'camiseta',
        ));

        $product = Product::factory()->create([
            'source_name' => 'Camiseta',
            'composition' => null,
        ]);

        app(ProductGenerationService::class)->generate($product->getKey());

        $warnings = $product->refresh()->contentFor()->warnings();

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('algodón orgánico', implode(' ', $warnings));
    }

    public function test_un_fallo_definitivo_deja_la_ficha_marcada_y_no_reintenta(): void
    {
        $this->fakeClient(failure: AiRequestFailed::permanent('Clave no válida.', 'unauthorized'));

        $product = $this->product();
        $product->status = ProductStatus::Generating;
        $product->save();

        app(ProductGenerationService::class)->generate($product->getKey());

        $this->assertSame(ProductStatus::GenerationFailed, $product->refresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'generation_failed',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_un_fallo_transitorio_se_propaga_para_que_la_cola_reintente(): void
    {
        $this->fakeClient(failure: AiRequestFailed::retryable('Proveedor no disponible.', 'provider_unavailable'));

        $product = $this->product();

        $this->expectException(AiRequestFailed::class);

        app(ProductGenerationService::class)->generate($product->getKey());
    }

    public function test_no_se_genera_contenido_para_una_ficha_archivada(): void
    {
        Queue::fake();
        $product = $this->product();
        $product->status = ProductStatus::Archived;
        $product->save();

        $this->expectException(RuntimeException::class);

        app(ProductGenerationService::class)->request($product, $this->operadora());
    }

    public function test_se_aplica_el_limite_diario_de_generaciones(): void
    {
        Queue::fake();
        config()->set('product-studio.content.max_generations_per_day', 2);

        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductGenerationService::class)->request($product, $operadora);
        app(ProductGenerationService::class)->request($product, $operadora);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/límite diario/');

        app(ProductGenerationService::class)->request($product, $operadora);
    }

    public function test_el_limite_se_cuenta_por_ficha(): void
    {
        Queue::fake();
        config()->set('product-studio.content.max_generations_per_day', 1);

        $operadora = $this->operadora();
        $uno = $this->product();
        $dos = $this->product();

        $service = app(ProductGenerationService::class);
        $service->request($uno, $operadora);

        // Otra ficha tiene su propia cuota: agotar la de una no afecta a la otra.
        $service->request($dos, $operadora);

        $limiter = app(GenerationLimiter::class);

        $this->assertSame(0, $limiter->remainingToday($uno));
        $this->assertSame(0, $limiter->remainingToday($dos));
        $this->assertSame(1, $limiter->usedToday($uno));
        $this->assertSame(1, $limiter->usedToday($dos));
    }

    public function test_regenerar_un_campo_no_sobrescribe_la_edicion_manual_de_otro(): void
    {
        // Criterio de RFC-0003: un cambio manual no se sobrescribe al regenerar
        // otro campo.
        $this->fakeClient(new ContentProposal(
            title: 'Título regenerado por IA',
            shortBenefit: null,
            htmlDescription: '<p>Descripción regenerada por IA.</p>',
            seoTitle: 'Camiseta Dies de Platja',
            seoDescription: 'Camiseta de algodón.',
            handleSuggestion: 'camiseta',
        ));

        $product = $this->product();

        ProductContent::factory()->forProduct($product)->create([
            'title' => 'Título revisado a mano por la operadora',
            'html_description' => '<p>Descripción escrita a mano.</p>',
        ]);

        app(ProductGenerationService::class)->generate($product->getKey(), 'html_description');

        $content = $product->refresh()->contentFor();

        $this->assertSame('Título revisado a mano por la operadora', $content->title);
        $this->assertStringContainsString('regenerada por IA', $content->html_description);
    }

    public function test_la_generacion_documenta_la_version_de_prompt_usada(): void
    {
        $this->fakeClient();
        $product = $this->product();

        app(ProductGenerationService::class)->generate($product->getKey());

        $this->assertSame(PromptBuilder::VERSION, $product->refresh()->contentFor()->prompt_version);
    }

    public function test_el_trabajo_es_unico_por_ficha(): void
    {
        $job = new GenerateProductContentJob(42);
        $other = new GenerateProductContentJob(43);

        $this->assertSame('generate-content:42', $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), $other->uniqueId());
    }
}
