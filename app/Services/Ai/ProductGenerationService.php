<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\AiClient;
use App\DataObjects\Ai\AiGenerationResult;
use App\DataObjects\Ai\ContentGenerationRequest;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Exceptions\Ai\AiRequestFailed;
use App\Jobs\GenerateProductContentJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductContentService;
use App\Support\Ai\ContentProfile;
use App\Support\Ai\GenerationLimiter;
use App\Support\Ai\ImagePreparer;
use App\Support\Ai\ProductFactSheet;
use App\Support\Ai\ProhibitedClaimsChecker;
use App\Support\Audit\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orquesta la generación de contenido (RFC-0003).
 *
 * Responsabilidades:
 *
 * - Comprobar el límite diario y marcar la ficha como `generating`.
 * - Encolar el trabajo con bloqueo por producto (una generación por ficha).
 * - Al terminar, guardar la propuesta como versión nueva y volver a `review`.
 * - Dejar en auditoría el modelo, la versión de prompt, los tokens y la latencia.
 *
 * La generación **no** aprueba nada: sólo propone. Y un campo editado a mano no
 * se sobrescribe al regenerar otro campo, porque la regeneración parcial sólo
 * escribe las claves del campo pedido.
 */
class ProductGenerationService
{
    public function __construct(
        private readonly AiClient $client,
        private readonly ImagePreparer $images,
        private readonly ProhibitedClaimsChecker $claims,
        private readonly GenerationLimiter $limiter,
        private readonly ProductContentService $contents,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Encola una generación completa (RFC-0003: una tarea asíncrona por ficha).
     */
    public function request(Product $product, User $author): void
    {
        $this->guardCanGenerate($product);

        $this->limiter->record($product);

        DB::transaction(function () use ($product, $author): void {
            if ($product->status->canTransitionTo(ProductStatus::Generating)) {
                $product->transitionTo(ProductStatus::Generating);
            }

            $this->recorder->record(
                ActivityEvent::GenerationRequested,
                $product,
                'Generación de propuesta solicitada.',
                ['model' => $this->client->model()],
                actor: $author,
            );
        });

        GenerateProductContentJob::dispatch($product->getKey());
    }

    /**
     * Regenera un único campo sin tocar los demás (RFC-0003).
     */
    public function requestFieldRegeneration(Product $product, string $field, User $author): void
    {
        $this->guardCanGenerate($product);

        $this->limiter->record($product);

        $this->recorder->record(
            ActivityEvent::GenerationRequested,
            $product,
            "Regeneración del campo «{$field}» solicitada.",
            ['field' => $field, 'model' => $this->client->model()],
            actor: $author,
        );

        GenerateProductContentJob::dispatch($product->getKey(), $field);
    }

    /**
     * Ejecuta la generación. Es lo que corre dentro del trabajo en cola.
     */
    public function generate(int $productId, ?string $regenerateField = null): void
    {
        $product = Product::with([
            'variants',
            'media',
            'contents',
            'technicalSheets',
            'technicalSheetComposition',
            'technicalSheetFit',
            'technicalSheetCare',
            'technicalSheetSizeGuide',
        ])->find($productId);

        if ($product === null) {
            Log::info('Generación de contenido omitida: la ficha ya no existe.', ['product_id' => $productId]);

            return;
        }

        if ($product->status->isTerminal()) {
            Log::info('Generación de contenido omitida: la ficha está archivada.', ['product_id' => $productId]);

            return;
        }

        $request = $this->buildRequest($product, $regenerateField);

        try {
            $result = $this->client->generate($request);
        } catch (AiRequestFailed $failure) {
            $this->handleFailure($product, $failure);

            // Un fallo definitivo no se reintenta; uno transitorio sí, para que
            // la cola aplique el backoff configurado.
            if (! $failure->isRetryable) {
                return;
            }

            throw $failure;
        }

        $this->persistProposal($product, $result, $regenerateField);
    }

    private function buildRequest(Product $product, ?string $regenerateField): ContentGenerationRequest
    {
        $facts = ProductFactSheet::fromProduct($product);

        $latest = $product->contentFor();

        return new ContentGenerationRequest(
            facts: $facts,
            profile: ContentProfile::forProductType($product->product_type),
            locale: Locale::Es,
            imageDataUris: $this->images->forProduct($product),
            existingTags: $latest?->tags() ?? [],
            warnings: $latest?->warnings() ?? [],
            regenerateField: $regenerateField,
        );
    }

    private function persistProposal(Product $product, AiGenerationResult $result, ?string $regenerateField): void
    {
        $proposal = $result->proposal;
        $facts = ProductFactSheet::fromProduct($product);

        // Las afirmaciones no respaldadas se convierten en advertencias: la
        // persona debe verlas antes de aprobar (RFC-0003).
        $claimWarnings = $this->claims->inspect($proposal, $facts);
        $warnings = array_values(array_unique([...$proposal->warnings, ...$claimWarnings]));

        $attributes = $proposal->toContentAttributes();
        $attributes['warnings_json'] = $warnings;

        if ($regenerateField !== null) {
            // Regeneración parcial: sólo se escribe el campo pedido, para no
            // pisar lo que la persona ya había corregido a mano.
            $attributes = $proposal->only($regenerateField);
            $attributes['warnings_json'] = $warnings;
        }

        $attributes['ai_model'] = $result->model;
        $attributes['prompt_version'] = $result->promptVersion;
        $attributes['generated_at'] = now();

        DB::transaction(function () use ($product, $attributes, $warnings, $result, $regenerateField): void {
            // La propuesta la escribe el sistema: no hay persona detrás de un
            // trabajo en cola, y la auditoría debe decirlo así.
            $this->contents->saveProposal($product, $attributes, null);

            if ($product->status === ProductStatus::Generating) {
                $product->transitionTo(ProductStatus::Review);
            }

            $this->recorder->recordSystem(
                ActivityEvent::GenerationSucceeded,
                $product,
                $regenerateField === null
                    ? 'Propuesta de contenido generada.'
                    : "Campo «{$regenerateField}» regenerado.",
                [
                    ...$result->toAuditArray(),
                    'warnings_count' => count($warnings),
                    'regenerated_field' => $regenerateField,
                ],
            );
        });
    }

    private function handleFailure(Product $product, AiRequestFailed $failure): void
    {
        $wasGenerating = $product->status === ProductStatus::Generating;

        DB::transaction(function () use ($product, $failure, $wasGenerating): void {
            if ($wasGenerating && $product->status->canTransitionTo(ProductStatus::GenerationFailed)) {
                $product->transitionTo(ProductStatus::GenerationFailed);
            }

            $this->recorder->recordSystem(
                ActivityEvent::GenerationFailed,
                $product,
                $failure->getMessage(),
                [
                    'error_code' => $failure->errorCode,
                    'retryable' => $failure->isRetryable,
                    'model' => $this->client->model(),
                ],
            );
        });

        Log::warning('Fallo al generar contenido con IA.', [
            'product_id' => $product->getKey(),
            'error_code' => $failure->errorCode,
            'retryable' => $failure->isRetryable,
        ]);
    }

    private function guardCanGenerate(Product $product): void
    {
        if ($product->status->isTerminal()) {
            throw new RuntimeException('No se puede generar contenido para una ficha archivada.');
        }

        if (! $this->limiter->canGenerate($product)) {
            $this->limiter->recordExceeded($product);

            throw new RuntimeException(
                'Has alcanzado el límite diario de generaciones para esta ficha ('
                .$this->limiter->limitPerDay().'). Inténtalo mañana.'
            );
        }
    }
}
