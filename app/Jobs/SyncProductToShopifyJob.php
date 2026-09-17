<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Shopify\ProductSyncService;
use App\Support\Products\ProductLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza una ficha con Shopify en segundo plano (RFC-0004).
 *
 * Dos protecciones contra el doble envío, que es el criterio de aceptación
 * principal del RFC («dos clics seguidos terminan con un único producto»):
 *
 * - `ShouldBeUnique` impide encolar dos veces la misma ficha.
 * - `ProductLock` impide que dos trabajos operen a la vez sobre ella, de modo
 *   que un doble clic no llega a crear dos productos ni sube dos veces la misma
 *   imagen.
 */
class SyncProductToShopifyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> segundos de espera entre reintentos (RFC-0004: backoff). */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $productId,
        public readonly ?int $requestedBy = null,
    ) {
        $this->onQueue((string) config('product-studio.queues.sync', 'shopify'));
    }

    public function uniqueId(): string
    {
        return 'sync-shopify:'.$this->productId;
    }

    /**
     * Ventana de unicidad: si el bloqueo por producto caduca antes, un segundo
     * envío debe poder entrar en lugar de quedar bloqueado para siempre.
     */
    public function uniqueFor(): int
    {
        return (int) config('product-studio.queues.product_lock_seconds', 900);
    }

    public function handle(ProductSyncService $service): void
    {
        ProductLock::run($this->productId, function () use ($service): void {
            $service->sync($this->productId);
        });
    }
}
