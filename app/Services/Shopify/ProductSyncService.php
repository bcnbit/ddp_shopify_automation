<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\DataObjects\Shopify\ShopifyProductPayload;
use App\DataObjects\Shopify\ShopifySyncResult;
use App\Enums\ActivityEvent;
use App\Enums\MediaUploadStatus;
use App\Enums\ProductStatus;
use App\Enums\SyncOperation;
use App\Enums\SyncStatus;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Jobs\SyncProductToShopifyJob;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Models\User;
use App\Support\Audit\ActivityRecorder;
use App\Support\Products\IdempotencyKey;
use App\Support\Products\ProductReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orquesta la sincronización con Shopify (RFC-0004).
 *
 * El flujo completo, en el orden que pide el RFC:
 *
 * 1. Validar la ficha local (si hay bloqueantes, ni se llama a Shopify).
 * 2. Calcular la clave de idempotencia.
 * 3. Crear o actualizar el producto con estado DRAFT.
 * 4. Sincronizar opciones y variantes.
 * 5. Subir y asociar medios con su ALT.
 * 6. Actualizar SEO, handle, tags, tipo y proveedor.
 * 7. Guardar los GID devueltos y registrar el intento.
 *
 * El reintento **continúa** desde donde se quedó: los GID ya guardados se
 * reutilizan y los medios con `sha256` conocido no se vuelven a subir.
 */
class ProductSyncService
{
    public function __construct(
        private readonly ShopifyProductGateway $gateway,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Encola el envío como borrador. Es lo que dispara el botón del panel.
     */
    public function request(Product $product, User $author): SyncAttempt
    {
        $this->guardCanSync($product);

        $attempt = $this->recordRequest($product, $author, SyncOperation::CreateProduct);

        SyncProductToShopifyJob::dispatch($product->getKey(), $author->getKey());

        return $attempt;
    }

    /**
     * Reintenta un intento fallido continuando desde el punto en que quedó.
     */
    public function retry(SyncAttempt $attempt, User $author): SyncAttempt
    {
        $product = $attempt->product;

        if ($product === null) {
            throw new RuntimeException('La ficha de este intento ya no existe.');
        }

        $this->guardCanSync($product);

        $pending = $this->recordRequest($product, $author, $attempt->operation, $attempt);

        $this->recorder->record(
            ActivityEvent::SyncRetried,
            $product,
            "Reintento de sincronización ({$pending->support_reference}).",
            ['previous_attempt_id' => $attempt->getKey(), 'attempt_id' => $pending->getKey()],
            actor: $author,
        );

        SyncProductToShopifyJob::dispatch($product->getKey(), $author->getKey());

        return $pending;
    }

    /**
     * Ejecuta la sincronización. Es lo que corre dentro del trabajo en cola.
     */
    public function sync(int $productId): void
    {
        $product = Product::with(['variants', 'media', 'contents'])->find($productId);

        if ($product === null) {
            Log::info('Sincronización omitida: la ficha ya no existe.', ['product_id' => $productId]);

            return;
        }

        if ($product->status->isTerminal()) {
            Log::info('Sincronización omitida: la ficha está archivada.', ['product_id' => $productId]);

            return;
        }

        $validation = ProductReadiness::validation($product);

        if ($validation->fails()) {
            $this->fail($product, null, 'La ficha tiene errores bloqueantes y no se ha enviado.', 'validation_failed', false);

            $product->transitionTo(ProductStatus::ValidationFailed);

            return;
        }

        $attempt = $this->currentAttempt($product);

        $attempt?->markRunning();

        $payload = ShopifyProductPayload::fromProduct($product);

        try {
            $result = $this->gateway->createOrUpdateDraft($payload, $product->shopify_product_gid);
        } catch (ShopifyRequestFailed $failure) {
            $this->handleFailure($product, $attempt, $failure);

            // Un fallo definitivo no se reintenta; uno transitorio sí, para que
            // la cola aplique el backoff configurado y el reintento continúe.
            if (! $failure->isRetryable) {
                return;
            }

            throw $failure;
        }

        $this->persistResult($product, $attempt, $result);
    }

    /**
     * Registra la intención y deja la ficha en `syncing` (RFC-0002 / RFC-0004).
     */
    private function recordRequest(Product $product, User $author, SyncOperation $operation, ?SyncAttempt $previous = null): SyncAttempt
    {
        return DB::transaction(function () use ($product, $author, $operation, $previous): SyncAttempt {
            $key = IdempotencyKey::make(
                $product->getKey(),
                $product->latestContentVersion(),
                $operation,
            );

            $attemptNumber = 1;

            if ($previous !== null) {
                $attemptNumber = $previous->attempt_number + 1;
            } else {
                $attemptNumber = (int) $product->syncAttempts()
                    ->where('idempotency_key', $key)
                    ->max('attempt_number') + 1;
            }

            $attempt = SyncAttempt::create([
                'product_id' => $product->getKey(),
                'operation' => $operation->value,
                'idempotency_key' => $key,
                'attempt_number' => $attemptNumber,
                'status' => SyncStatus::Pending->value,
                'attempted_by' => $author->getKey(),
            ]);

            if ($product->status->canTransitionTo(ProductStatus::Syncing)) {
                $product->transitionTo(ProductStatus::Syncing);
            }

            $this->recorder->record(
                ActivityEvent::SyncRequested,
                $product,
                'Envío como borrador solicitado.',
                [
                    'attempt_id' => $attempt->getKey(),
                    'operation' => $operation->value,
                    'idempotency_key' => $key,
                ],
                actor: $author,
            );

            return $attempt;
        });
    }

    /**
     * Último intento pendiente o fallido de la ficha, si lo hay.
     */
    private function currentAttempt(Product $product): ?SyncAttempt
    {
        return $product->syncAttempts()
            ->whereIn('status', [SyncStatus::Pending->value, SyncStatus::Running->value, SyncStatus::Failed->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Guarda los GID devueltos y deja la ficha como borrador en Shopify.
     */
    private function persistResult(Product $product, ?SyncAttempt $attempt, ShopifySyncResult $result): void
    {
        DB::transaction(function () use ($product, $attempt, $result): void {
            $product->shopify_product_gid = $result->productGid;
            $product->shopify_handle = $result->handle ?? $product->shopify_handle;
            $product->last_synced_at = now();
            $product->save();

            // Los GID de variante se resuelven por SKU: es la clave estable que
            // comparten la ficha local y Shopify.
            foreach ($product->variants as $variant) {
                $gid = $result->variantGids[$variant->sku] ?? null;

                if ($gid !== null && $variant->shopify_variant_gid !== $gid) {
                    $variant->shopify_variant_gid = $gid;
                    $variant->save();
                }
            }

            // Los medios se resuelven por `sha256` para que un reintento no
            // vuelva a subir una imagen que ya está en Shopify.
            foreach ($product->media as $media) {
                $gid = $result->mediaGids[$media->sha256] ?? null;

                if ($gid !== null && $media->shopify_media_gid !== $gid) {
                    $media->shopify_media_gid = $gid;
                    $media->upload_status = MediaUploadStatus::Uploaded;
                    $media->save();
                }
            }

            if ($product->status->canTransitionTo(ProductStatus::ShopifyDraft)) {
                $product->transitionTo(ProductStatus::ShopifyDraft);
            }

            $attempt?->markSucceeded($result->toArray());

            $this->recorder->recordSystem(
                ActivityEvent::SyncSucceeded,
                $product,
                'Producto enviado a Shopify como borrador.',
                [
                    'attempt_id' => $attempt?->getKey(),
                    'shopify_product_gid' => $result->productGid,
                    'handle' => $result->handle,
                ],
            );
        });
    }

    /**
     * Traduce un fallo del conector a un estado legible y recuperable.
     */
    private function handleFailure(Product $product, ?SyncAttempt $attempt, ShopifyRequestFailed $failure): void
    {
        $this->fail($product, $attempt, $failure->getMessage(), $failure->errorCode, $failure->isRetryable);

        Log::warning('Fallo al sincronizar con Shopify.', [
            'product_id' => $product->getKey(),
            'sync_attempt_id' => $attempt?->getKey(),
            'error_code' => $failure->errorCode,
            'retryable' => $failure->isRetryable,
        ]);
    }

    private function fail(Product $product, ?SyncAttempt $attempt, string $message, ?string $code, bool $retryable): void
    {
        DB::transaction(function () use ($product, $attempt, $message, $code, $retryable): void {
            $attempt?->markFailed($message, $code, $retryable);

            if ($product->status->canTransitionTo(ProductStatus::SyncFailed)) {
                $product->transitionTo(ProductStatus::SyncFailed);
            }

            $this->recorder->recordSystem(
                ActivityEvent::SyncFailed,
                $product,
                $message,
                [
                    'attempt_id' => $attempt?->getKey(),
                    'error_code' => $code,
                    'retryable' => $retryable,
                    'support_reference' => $attempt?->support_reference,
                ],
            );
        });
    }

    /**
     * Reglas comunes antes de encolar: la ficha debe poder enviarse de verdad.
     */
    private function guardCanSync(Product $product): void
    {
        if ($product->status->isTerminal()) {
            throw new RuntimeException('No se puede enviar una ficha archivada.');
        }

        if (! $this->gateway->isConfigured()) {
            throw new RuntimeException('Shopify no está configurado. Avisa al administrador técnico.');
        }

        if (! ProductReadiness::canSendToShopify($product)) {
            throw new RuntimeException('La ficha tiene errores bloqueantes que impiden enviarla.');
        }
    }
}
