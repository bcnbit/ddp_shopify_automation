<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ai\ProductGenerationService;
use App\Support\Products\ProductLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Genera la propuesta de contenido en segundo plano (RFC-0003).
 *
 * Es una tarea por ficha, con progreso visible, y con dos protecciones:
 *
 * - `ShouldBeUnique` evita encolar dos veces la misma ficha.
 * - `ProductLock` evita que dos trabajos operen a la vez sobre ella, de modo
 *   que un doble clic no genera dos propuestas ni duplica el gasto.
 */
class GenerateProductContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> segundos de espera entre reintentos (RFC-0003: backoff). */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $productId,
        public readonly ?string $regenerateField = null,
    ) {
        $this->onQueue((string) config('product-studio.queues.generation', 'ai'));
    }

    public function uniqueId(): string
    {
        return 'generate-content:'.$this->productId;
    }

    public function handle(ProductGenerationService $service): void
    {
        ProductLock::run($this->productId, function () use ($service): void {
            $service->generate($this->productId, $this->regenerateField);
        });
    }

    /**
     * Si el producto se ha borrado mientras esperaba, no hay nada que hacer.
     *
     * @return list<class-string>
     */
    public function middleware(): array
    {
        return [];
    }
}
