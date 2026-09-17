<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\SyncOperation;
use App\Support\Products\IdempotencyKey;
use App\Support\Products\SkuNormalizer;
use PHPUnit\Framework\TestCase as BaseTestCase;

class SkuNormalizerTest extends BaseTestCase
{
    public function test_normaliza_a_mayusculas_sin_espacios(): void
    {
        $this->assertSame('DDP-1001-M-BLANCO', SkuNormalizer::normalize('ddp 1001 m blanco'));
    }

    public function test_colapsa_separadores_repetidos(): void
    {
        $this->assertSame('DDP-1001', SkuNormalizer::normalize('DDP---1001'));
        $this->assertSame('DDP-1001', SkuNormalizer::normalize('  __ddp_-_1001__  '));
    }

    public function test_trata_el_nulo_sin_error(): void
    {
        $this->assertNull(SkuNormalizer::normalize(null));
        $this->assertNull(SkuNormalizer::normalize(''));
        $this->assertNull(SkuNormalizer::normalize('   '));
    }

    public function test_dos_skus_equivalentes_producen_la_misma_clave(): void
    {
        $this->assertSame(
            SkuNormalizer::normalize('ddp 1001'),
            SkuNormalizer::normalize('DDP-1001'),
        );
    }

    public function test_la_clave_de_idempotencia_es_estable_y_depende_de_la_version(): void
    {
        $a = IdempotencyKey::make(10, 1, SyncOperation::CreateProduct);
        $b = IdempotencyKey::make(10, 1, SyncOperation::CreateProduct);
        $c = IdempotencyKey::make(10, 2, SyncOperation::CreateProduct);
        $d = IdempotencyKey::make(11, 1, SyncOperation::CreateProduct);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
        $this->assertSame(64, strlen($a));
    }

    public function test_la_operacion_forma_parte_de_la_clave(): void
    {
        $this->assertNotSame(
            IdempotencyKey::make(10, 1, SyncOperation::CreateProduct),
            IdempotencyKey::make(10, 1, SyncOperation::UpdateProduct),
        );
    }
}
