<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Bloqueo único por producto (RFC-0001 / RFC-0002).
 *
 * Evita que dos trabajos de generación o sincronización operen sobre la misma
 * ficha a la vez. Un segundo intento se rechaza de inmediato en lugar de
 * encolarse a ciegas, para que la usuaria reciba una respuesta clara.
 */
final class ProductLock
{
    public static function key(Product|int $product): string
    {
        $id = $product instanceof Product ? $product->getKey() : $product;

        return 'product-studio:product-lock:'.$id;
    }

    /**
     * @param  callable(): mixed  $callback
     *
     * @throws RuntimeException si ya hay un trabajo en curso para ese producto
     */
    public static function run(Product|int $product, callable $callback): mixed
    {
        $lock = self::acquire($product);

        if ($lock === null) {
            throw new RuntimeException('Ya hay una tarea en curso para esta ficha. Inténtalo de nuevo cuando termine.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public static function acquire(Product|int $product, ?int $seconds = null): ?Lock
    {
        $seconds ??= (int) config('product-studio.queues.product_lock_seconds', 900);

        $lock = Cache::lock(self::key($product), $seconds);

        return $lock->get() ? $lock : null;
    }

    /**
     * Comprueba el estado del bloqueo intentando adquirirlo.
     *
     * El contrato `Lock` de Laravel no expone `isLocked()`, así que se consulta
     * de forma neutral respecto al driver: si se puede adquirir, estaba libre.
     */
    public static function isLocked(Product|int $product): bool
    {
        $lock = Cache::lock(self::key($product), 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }
}
