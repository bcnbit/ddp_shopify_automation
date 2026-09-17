<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\ActivityEvent;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\ActivityRecorder;
use App\Support\Products\ProductValidator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operaciones de ciclo de vida de una ficha (RFC-0002).
 *
 * Mantiene fuera de la interfaz las reglas de estado y la auditoría, para que
 * cualquier punto de entrada (panel, comando, futura API) aplique lo mismo.
 */
class ProductService
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $author): Product
    {
        return DB::transaction(function () use ($attributes, $author): Product {
            $product = new Product($attributes);
            $product->status = ProductStatus::Draft;
            $product->created_by = $author->getKey();
            $product->save();

            $this->recorder->record(
                ActivityEvent::Created,
                $product,
                'Ficha creada.',
                actor: $author,
            );

            return $product;
        });
    }

    /**
     * Aprueba el contenido y la ficha para poder enviarla como borrador.
     *
     * Aprobar no publica: sólo habilita el envío (RFC-0000/RFC-0002).
     */
    public function approve(Product $product, User $approver): bool
    {
        $validation = (new ProductValidator)->validate($product);

        if ($validation->fails()) {
            throw new RuntimeException(
                'No se puede aprobar: hay errores bloqueantes. '.implode(' ', $validation->blockingMessages())
            );
        }

        if (! $product->status->canTransitionTo(ProductStatus::Approved)) {
            throw new RuntimeException("La ficha no se puede aprobar desde el estado «{$product->status->label()}».");
        }

        $previous = $product->status;

        return DB::transaction(function () use ($product, $approver, $previous): bool {
            $product->status = ProductStatus::Approved;
            $product->approved_by = $approver->getKey();
            $product->approved_at = now();
            $product->save();

            $content = $product->contentFor();

            if ($content !== null && ! $content->isApproved()) {
                $content->approved_at = now();
                $content->approved_by = $approver->getKey();
                $content->save();
            }

            $this->recorder->record(
                ActivityEvent::Approved,
                $product,
                'Ficha aprobada para envío como borrador.',
                ['from' => $previous->value, 'to' => ProductStatus::Approved->value],
                actor: $approver,
            );

            return true;
        });
    }

    /**
     * Devuelve la ficha a revisión para corregirla (RFC-0002).
     */
    public function returnToReview(Product $product, User $author): bool
    {
        if (! $product->status->canTransitionTo(ProductStatus::Review)) {
            throw new RuntimeException("La ficha no puede volver a revisión desde «{$product->status->label()}».");
        }

        $previous = $product->status;

        return DB::transaction(function () use ($product, $author, $previous): bool {
            $product->transitionTo(ProductStatus::Review);

            $this->recorder->record(
                ActivityEvent::StatusChanged,
                $product,
                'Ficha devuelta a revisión.',
                ['from' => $previous->value, 'to' => ProductStatus::Review->value],
                actor: $author,
            );

            return true;
        });
    }

    /**
     * Registra la intención de enviar la ficha a Shopify.
     *
     * En RFC-0002 el envío real no existe todavía: esta operación deja la ficha
     * en `syncing` y deja rastro en auditoría. El conector es RFC-0004.
     */
    public function markAsSyncing(Product $product, User $author): bool
    {
        if (! $product->status->canTransitionTo(ProductStatus::Syncing)) {
            throw new RuntimeException("La ficha no puede sincronizarse desde «{$product->status->label()}».");
        }

        $previous = $product->status;

        return DB::transaction(function () use ($product, $author, $previous): bool {
            $product->transitionTo(ProductStatus::Syncing);

            $this->recorder->record(
                ActivityEvent::SyncRequested,
                $product,
                'Envío como borrador solicitado.',
                ['from' => $previous->value, 'to' => ProductStatus::Syncing->value],
                actor: $author,
            );

            return true;
        });
    }

    public function archive(Product $product, User $author): bool
    {
        if (! $product->status->canTransitionTo(ProductStatus::Archived)) {
            throw new RuntimeException("La ficha no puede archivarse desde «{$product->status->label()}».");
        }

        $previous = $product->status;

        return DB::transaction(function () use ($product, $author, $previous): bool {
            $product->transitionTo(ProductStatus::Archived);

            $this->recorder->record(
                ActivityEvent::Archived,
                $product,
                'Ficha archivada.',
                ['from' => $previous->value, 'to' => ProductStatus::Archived->value],
                actor: $author,
            );

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes, User $author): Product
    {
        return DB::transaction(function () use ($product, $attributes, $author): Product {
            $dirty = [];

            foreach ($attributes as $key => $value) {
                if (! $product->isFillable($key)) {
                    continue;
                }

                if ($product->getAttribute($key) != $value) {
                    $dirty[$key] = $value;
                }
            }

            if ($dirty !== []) {
                $original = $product->getOriginal();
                $product->fill($dirty);
                $product->save();

                $old = [];
                $new = [];

                foreach (array_keys($dirty) as $key) {
                    $old[$key] = $original[$key] ?? null;
                    $new[$key] = $product->getAttribute($key);
                }

                $this->recorder->recordChanges(
                    ActivityEvent::Updated,
                    $product,
                    $old,
                    $new,
                    'Ficha actualizada.',
                    $author,
                );
            }

            return $product;
        });
    }
}
