<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Support\Products\ProductLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloquea_un_segundo_trabajo_sobre_el_mismo_producto(): void
    {
        $product = Product::factory()->create();

        ProductLock::run($product, function () use ($product): void {
            $this->assertTrue(ProductLock::isLocked($product));

            $this->expectException(RuntimeException::class);

            ProductLock::run($product, fn () => 'no debería ejecutarse');
        });
    }

    public function test_libera_el_bloqueo_al_terminar(): void
    {
        $product = Product::factory()->create();

        $result = ProductLock::run($product, fn (): string => 'hecho');

        $this->assertSame('hecho', $result);
        $this->assertFalse(ProductLock::isLocked($product));
    }

    public function test_libera_el_bloqueo_aunque_el_trabajo_falle(): void
    {
        $product = Product::factory()->create();

        try {
            ProductLock::run($product, function (): void {
                throw new RuntimeException('fallo simulado');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertFalse(ProductLock::isLocked($product));
    }

    public function test_productos_distintos_tienen_bloqueos_independientes(): void
    {
        $uno = Product::factory()->create();
        $dos = Product::factory()->create();

        ProductLock::run($uno, function () use ($dos): void {
            $this->assertFalse(ProductLock::isLocked($dos));
        });
    }
}
