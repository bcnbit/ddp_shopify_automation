<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SyncOperation;
use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAttemptModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_el_intento_con_su_clave_de_idempotencia(): void
    {
        $attempt = SyncAttempt::factory()->create();

        $this->assertSame(SyncStatus::Pending, $attempt->status);
        $this->assertSame(64, strlen($attempt->idempotency_key));
        $this->assertSame(SyncOperation::CreateProduct, $attempt->operation);
    }

    public function test_permite_reintentos_con_la_misma_clave_de_idempotencia(): void
    {
        $product = Product::factory()->create();

        SyncAttempt::factory()->forProduct($product)->create(['idempotency_key' => str_repeat('a', 64)]);
        SyncAttempt::factory()->forProduct($product)->create([
            'idempotency_key' => str_repeat('a', 64),
            'attempt_number' => 2,
        ]);

        $this->assertSame(2, SyncAttempt::count());
    }

    public function test_marca_un_intento_correcto(): void
    {
        $attempt = SyncAttempt::factory()->create();
        $attempt->markSucceeded(['product' => ['id' => 'gid://shopify/Product/1']]);

        $this->assertSame(SyncStatus::Succeeded, $attempt->fresh()->status);
        $this->assertTrue($attempt->fresh()->status->isFinal());
        $this->assertNotNull($attempt->fresh()->finished_at);
    }

    public function test_marca_un_intento_fallido_con_referencia_de_soporte(): void
    {
        $attempt = SyncAttempt::factory()->create();
        $attempt->markFailed('La API ha devuelto un error temporal.', 'THROTTLED', retryable: true);

        $fresh = $attempt->fresh();

        $this->assertTrue($fresh->isFailed());
        $this->assertTrue($fresh->is_retryable);
        $this->assertSame('THROTTLED', $fresh->error_code);
        $this->assertStringStartsWith('SYNC-', $fresh->support_reference);
    }

    public function test_conserva_la_referencia_de_soporte_en_un_segundo_fallo(): void
    {
        $attempt = SyncAttempt::factory()->create(['support_reference' => 'SYNC-00000042']);
        $attempt->markFailed('Otro error.');

        $this->assertSame('SYNC-00000042', $attempt->fresh()->support_reference);
    }

    public function test_guarda_la_peticion_y_la_respuesta_saneadas(): void
    {
        $attempt = SyncAttempt::factory()->create([
            'request_payload' => ['title' => 'Camiseta', 'status' => 'DRAFT'],
        ]);
        $attempt->markSucceeded(['product' => ['id' => 'gid://shopify/Product/9']]);

        $this->assertSame('Camiseta', $attempt->fresh()->request_payload['title']);
        $this->assertSame('DRAFT', $attempt->fresh()->request_payload['status']);
        $this->assertArrayHasKey('product', $attempt->fresh()->response_payload);
    }

    public function test_cambia_a_en_curso_al_arrancar(): void
    {
        $attempt = SyncAttempt::factory()->create();
        $attempt->markRunning();

        $this->assertSame(SyncStatus::Running, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->started_at);
        $this->assertFalse($attempt->fresh()->status->isFinal());
    }
}
