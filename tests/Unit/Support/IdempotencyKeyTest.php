<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\SyncOperation;
use App\Support\Products\IdempotencyKey;
use PHPUnit\Framework\TestCase as BaseTestCase;

class IdempotencyKeyTest extends BaseTestCase
{
    public function test_dos_clics_seguidos_producen_la_misma_clave(): void
    {
        // Criterio de RFC-0004: dos clics no deben crear dos productos remotos.
        $first = IdempotencyKey::make(42, 3, SyncOperation::CreateProduct);
        $second = IdempotencyKey::make(42, 3, SyncOperation::CreateProduct);

        $this->assertSame($first, $second);
    }

    public function test_una_version_nueva_de_contenido_cambia_la_clave(): void
    {
        $this->assertNotSame(
            IdempotencyKey::make(42, 3, SyncOperation::CreateProduct),
            IdempotencyKey::make(42, 4, SyncOperation::CreateProduct),
        );
    }

    public function test_un_producto_sin_contenido_usa_cero_como_version(): void
    {
        $this->assertSame(
            IdempotencyKey::make(42, null, SyncOperation::CreateProduct),
            IdempotencyKey::make(42, '0', SyncOperation::CreateProduct),
        );
    }

    public function test_acepta_la_operacion_como_texto(): void
    {
        $this->assertSame(
            IdempotencyKey::make(1, 1, SyncOperation::UploadMedia),
            IdempotencyKey::make(1, 1, 'upload_media'),
        );
    }
}
