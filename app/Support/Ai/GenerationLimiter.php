<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ActivityEvent;
use App\Models\Product;
use App\Support\Audit\ActivityRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Limita las regeneraciones por ficha y día (RFC-0003).
 *
 * El límite es configurable por el administrador y evita que un uso repetido
 * dispare el gasto. Se cuenta por producto y por día natural, y se puede
 * consultar antes de encolar para informar a la usuaria en lugar de fallar
 * cuando ya está esperando.
 */
class GenerationLimiter
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    public function limitPerDay(): int
    {
        return max(1, (int) config('product-studio.content.max_generations_per_day', 10));
    }

    public function usedToday(Product $product): int
    {
        return (int) Cache::get($this->key($product), 0);
    }

    public function remainingToday(Product $product): int
    {
        return max(0, $this->limitPerDay() - $this->usedToday($product));
    }

    public function canGenerate(Product $product): bool
    {
        return $this->remainingToday($product) > 0;
    }

    /**
     * Registra un uso. Se llama al aceptar la solicitud, no al terminar, para
     * que dos peticiones simultáneas no consuman la misma cuota.
     */
    public function record(Product $product): void
    {
        $key = $this->key($product);
        $used = $this->usedToday($product);

        // La clave caduca a medianoche para que el contador sea diario.
        Cache::put($key, $used + 1, Carbon::tomorrow());
    }

    /**
     * Deja rastro de que se ha alcanzado el límite, para que quede en auditoría.
     */
    public function recordExceeded(Product $product): void
    {
        $this->recorder->record(
            ActivityEvent::GenerationFailed,
            $product,
            'Se ha alcanzado el límite diario de generaciones.',
            ['limit' => $this->limitPerDay(), 'used' => $this->usedToday($product)],
        );
    }

    public function reset(Product $product): void
    {
        Cache::forget($this->key($product));
    }

    private function key(Product $product): string
    {
        return 'ai-generations:'.$product->getKey().':'.now()->toDateString();
    }
}
