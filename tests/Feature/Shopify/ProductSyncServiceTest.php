<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\DataObjects\Shopify\ShopifySyncResult;
use App\Enums\ProductStatus;
use App\Enums\SyncOperation;
use App\Enums\SyncStatus;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Jobs\SyncProductToShopifyJob;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Services\Shopify\ProductSyncService;
use App\Support\Products\IdempotencyKey;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\BuildsSyncableProducts;
use Tests\Support\FakeShopifyGateway;
use Tests\TestCase;

/**
 * Orquestación de la sincronización con Shopify (RFC-0004).
 *
 * El doble de HTTP (`ShopifyGatewayTest`) verifica el **contrato** del conector;
 * aquí se verifica el **flujo**: cuántas veces se crea, con qué GID, qué pasa si
 * un intento falla y el siguiente no, y que nunca se publique.
 *
 * El criterio de aceptación principal del RFC es esta frase: «dos clics seguidos
 * sobre Enviar a Shopify terminan con un único producto remoto».
 */
class ProductSyncServiceTest extends TestCase
{
    use BuildsSyncableProducts;

    private FakeShopifyGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureShopify();
        $this->gateway = new FakeShopifyGateway;

        $this->app->instance(ShopifyProductGateway::class, $this->gateway);
        Queue::fake();
    }

    private function service(): ProductSyncService
    {
        return app(ProductSyncService::class);
    }

    // ---------------------------------------------------------------- envío

    public function test_encola_el_envio_y_deja_la_ficha_en_sincronizando(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $attempt = $this->service()->request($product, $operadora);

        Queue::assertPushed(SyncProductToShopifyJob::class, fn ($job): bool => $job->productId === $product->getKey());

        $this->assertSame(ProductStatus::Syncing, $product->refresh()->status);
        $this->assertSame(SyncStatus::Pending, $attempt->status);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame(SyncOperation::CreateProduct, $attempt->operation);
    }

    public function test_registra_el_intento_con_la_clave_de_idempotencia_calculada(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $attempt = $this->service()->request($product, $operadora);

        $expected = IdempotencyKey::make(
            $product->getKey(),
            $product->latestContentVersion(),
            SyncOperation::CreateProduct,
        );

        $this->assertSame($expected, $attempt->idempotency_key);
    }

    public function test_deja_rastro_en_auditoria_al_solicitar_el_envio(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'sync_requested',
            'subject_id' => $product->getKey(),
            'user_id' => $operadora->getKey(),
        ]);
    }

    public function test_no_envia_una_ficha_con_errores_bloqueantes(): void
    {
        $operadora = $this->operadora();

        // Sin variantes ni foto ni contenido: no puede salir.
        $product = Product::factory()->approved()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20.00,
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->request($product, $operadora);
    }

    public function test_no_envia_si_shopify_no_esta_configurado(): void
    {
        $this->gateway->configured = false;

        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify no está configurado');

        $this->service()->request($product, $operadora);
    }

    public function test_no_envia_una_ficha_archivada(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct(['status' => ProductStatus::Archived], $operadora);

        $this->expectException(RuntimeException::class);

        $this->service()->request($product, $operadora);
    }

    // ------------------------------------------------------------ ejecución

    public function test_una_sincronizacion_correcta_guarda_los_gid_y_marca_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $attempt = $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $product->refresh();

        $this->assertSame('gid://shopify/Product/1234567890', $product->shopify_product_gid);
        $this->assertSame('camiseta-marina', $product->shopify_handle);
        $this->assertNotNull($product->last_synced_at);
        $this->assertSame(ProductStatus::ShopifyDraft, $product->status);

        $attempt->refresh();
        $this->assertSame(SyncStatus::Succeeded, $attempt->status);
        $this->assertSame('gid://shopify/Product/1234567890', $attempt->response_payload['product_gid']);
    }

    public function test_guarda_los_gid_de_las_variantes_por_sku(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $bySku = $product->refresh()->variants->keyBy('sku');

        $this->assertSame('gid://shopify/ProductVariant/111', $bySku['DDP-1001-ROJO-M']->shopify_variant_gid);
        $this->assertSame('gid://shopify/ProductVariant/222', $bySku['DDP-1001-ROJO-L']->shopify_variant_gid);
    }

    public function test_guarda_los_gid_de_los_medios_por_sha256(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $media = $product->media->first();

        $this->gateway->willReturn(new ShopifySyncResult(
            productGid: 'gid://shopify/Product/1234567890',
            handle: 'camiseta-marina',
            variantGids: [],
            mediaGids: [$media->sha256 => 'gid://shopify/MediaImage/777'],
        ));

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $media->refresh();

        $this->assertSame('gid://shopify/MediaImage/777', $media->shopify_media_gid);
    }

    public function test_deja_rastro_en_auditoria_al_terminar(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $this->assertDatabaseHas('activity_log', [
            'event' => 'sync_succeeded',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_una_ficha_ya_sincronizada_se_actualiza_en_lugar_de_crear_otra(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        // Primer envío: crea el producto.
        $first = $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $gid = $product->refresh()->shopify_product_gid;

        // Segundo envío: la ficha ya conoce el GID, así que debe actualizar ese
        // mismo producto y no crear otro. Es el criterio «Actualizar borrador».
        $this->service()->request($product->refresh(), $operadora);
        $this->service()->sync($product->getKey());

        $this->assertSame(2, $this->gateway->callCount());

        // La segunda llamada lleva el GID conocido: no se crea nada nuevo.
        $this->assertSame($gid, $this->gateway->lastProductGid());

        $this->assertSame(1, Product::count());
        $this->assertSame($gid, $product->refresh()->shopify_product_gid);
        $this->assertNotSame($first->getKey(), $product->syncAttempts()->latest('id')->first()->getKey());
    }

    // -------------------------------------------------------- idempotencia

    public function test_dos_clics_seguidos_no_duplican_el_producto_remoto(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        // Primer clic: encola el envío.
        $this->service()->request($product, $operadora);

        // Segundo clic, mientras el primero sigue en curso. No debe encolar un
        // segundo trabajo ni, por tanto, crear un producto remoto de más.
        Queue::fake();

        try {
            $this->service()->request($product->refresh(), $operadora);
            $this->fail('El segundo envío simultáneo debería rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ya se está enviando', $exception->getMessage());
        }

        Queue::assertNothingPushed();

        // El trabajo encolado se ejecuta dos veces (un reintento de la cola), y
        // aun así sólo existe un producto remoto.
        $this->service()->sync($product->getKey());
        $this->service()->sync($product->getKey());

        $this->assertSame('gid://shopify/Product/1234567890', $product->refresh()->shopify_product_gid);

        // La segunda ejecución reutiliza el GID en lugar de crear otro producto.
        $this->assertSame(2, $this->gateway->callCount());
        $this->assertSame('gid://shopify/Product/1234567890', $this->gateway->lastProductGid());

        $this->assertSame(1, Product::count());
    }

    public function test_reutiliza_el_producto_existente_si_se_perdio_la_respuesta(): void
    {
        // El gateway encuentra el producto por el metafield: una ejecución previa
        // lo creó pero se perdió la respuesta.
        $this->gateway->alreadyExists(new ShopifySyncResult(
            productGid: 'gid://shopify/Product/4242',
            handle: 'camiseta-marina',
        ));

        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $this->assertSame('gid://shopify/Product/4242', $product->refresh()->shopify_product_gid);
    }

    public function test_nunca_envia_algo_que_no_sea_borrador(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        // El estado se fija dentro del gateway y no se recibe de fuera: se
        // comprueba que el payload que llega al conector es DRAFT.
        $payload = $this->gateway->lastPayload();

        $this->assertNotNull($payload);
        $this->assertSame('DRAFT', $payload->status);
        $this->assertTrue($payload->isDraft());

        // Y la ficha local queda como borrador, nunca como publicada.
        $this->assertSame(ProductStatus::ShopifyDraft, $product->refresh()->status);
        $this->assertNotSame(ProductStatus::Published, $product->status);
    }

    public function test_no_se_crea_ningun_intento_publicado(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $this->assertSame(
            0,
            $product->syncAttempts()->where('operation', SyncOperation::PublishProduct->value)->count(),
        );
    }

    // ------------------------------------------------------------- errores

    public function test_un_error_transitorio_marca_la_ficha_y_permite_reintentar(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->gateway->alwaysFailWith = ShopifyRequestFailed::retryable(
            'Shopify está limitando las llamadas. Se reintentará.',
            'throttled',
        );

        $attempt = $this->service()->request($product, $operadora);

        // Un fallo transitorio se propaga para que la cola aplique el backoff.
        try {
            $this->service()->sync($product->getKey());
            $this->fail('Debería haber propagado el fallo transitorio.');
        } catch (ShopifyRequestFailed) {
            // esperado
        }

        $product->refresh();
        $attempt->refresh();

        $this->assertSame(ProductStatus::SyncFailed, $product->status);
        $this->assertSame(SyncStatus::Failed, $attempt->status);
        $this->assertTrue($attempt->is_retryable);
        $this->assertNotNull($attempt->support_reference);
    }

    public function test_un_error_definitivo_no_se_propaga_y_deja_el_error_legible(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->gateway->alwaysFailWith = ShopifyRequestFailed::permanent(
            'El token de Shopify no es válido o no tiene permisos suficientes. Avisa al administrador técnico.',
            'unauthorized',
        );

        $attempt = $this->service()->request($product, $operadora);

        // No se propaga: un token inválido no se arregla reintentando.
        $this->service()->sync($product->getKey());

        $product->refresh();
        $attempt->refresh();

        $this->assertSame(ProductStatus::SyncFailed, $product->status);
        $this->assertFalse($attempt->is_retryable);
        $this->assertSame('unauthorized', $attempt->error_code);
        $this->assertStringContainsString('token de Shopify no es válido', (string) $attempt->error_message);

        // La referencia de soporte sirve para que la persona la cite.
        $this->assertNotNull($attempt->support_reference);
        $this->assertStringStartsWith('SYNC-', $attempt->support_reference);
    }

    public function test_el_error_queda_en_auditoria_con_la_referencia_de_soporte(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->gateway->alwaysFailWith = ShopifyRequestFailed::permanent('Fallo de prueba', 'test_error');

        $attempt = $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $log = $product->activityLogs()->where('event', 'sync_failed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($attempt->refresh()->support_reference, $log->properties['support_reference']);
    }

    public function test_el_reintento_continua_desde_el_gid_ya_guardado(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        // Primer envío correcto: la ficha ya conoce el GID.
        $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $gid = $product->refresh()->shopify_product_gid;

        // Segundo envío que falla: queda reintentable.
        $this->gateway->alwaysFailWith = ShopifyRequestFailed::retryable('Limitando llamadas.', 'throttled');

        $failed = $this->service()->request($product->refresh(), $operadora);

        try {
            $this->service()->sync($product->getKey());
        } catch (ShopifyRequestFailed) {
            // esperado
        }

        $this->assertTrue($failed->refresh()->is_retryable);

        // El reintento no reinicia: sigue apuntando al mismo producto remoto.
        $this->gateway->alwaysFailWith = null;

        $retried = $this->service()->retry($failed->refresh(), $operadora);
        $this->service()->sync($product->getKey());

        // El número de intento es correlativo dentro de la ficha, no un 2 fijo:
        // si antes hubo más envíos, el reintento continúa la cuenta.
        $this->assertGreaterThan($failed->attempt_number, $retried->attempt_number);
        $this->assertSame($gid, $this->gateway->lastProductGid());
        $this->assertSame($gid, $product->refresh()->shopify_product_gid);
    }

    public function test_el_reintento_no_duplica_el_intento_sino_que_crea_uno_nuevo(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct([], $operadora);

        $this->gateway->alwaysFailWith = ShopifyRequestFailed::permanent('Fallo', 'test_error');

        $attempt = $this->service()->request($product, $operadora);
        $this->service()->sync($product->getKey());

        $this->gateway->alwaysFailWith = null;

        $retried = $this->service()->retry($attempt->refresh(), $operadora);

        // Es un intento nuevo, no una edición del anterior.
        $this->assertNotSame($attempt->getKey(), $retried->getKey());
        // El número de intento es correlativo dentro de la ficha, no un 2 fijo:
        // si antes hubo más envíos, el reintento continúa la cuenta.
        $this->assertGreaterThan($attempt->attempt_number, $retried->attempt_number);

        // Y comparte la clave de idempotencia: mismo producto y misma operación.
        $this->assertSame($attempt->idempotency_key, $retried->idempotency_key);

        $this->assertSame(2, $product->syncAttempts()->count());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'sync_retried',
            'subject_id' => $product->getKey(),
        ]);
    }

    public function test_una_ficha_con_errores_bloqueantes_no_llama_a_shopify(): void
    {
        $operadora = $this->operadora();

        $product = Product::factory()->approved()->create([
            'created_by' => $operadora->getKey(),
            'price' => 20.00,
        ]);

        // Se fuerza el intento saltándose la guarda para probar la del propio
        // `sync()`, que es la que protege un trabajo ya encolado.
        SyncAttempt::factory()->forProduct($product)->create();

        $this->service()->sync($product->getKey());

        $this->assertSame(0, $this->gateway->callCount());
        $this->assertSame(ProductStatus::ValidationFailed, $product->refresh()->status);
    }

    public function test_omitir_una_ficha_inexistente_no_falla(): void
    {
        $this->service()->sync(999999);

        $this->assertSame(0, $this->gateway->callCount());
    }

    public function test_omitir_una_ficha_archivada_no_llama_a_shopify(): void
    {
        $operadora = $this->operadora();
        $product = $this->syncableProduct(['status' => ProductStatus::Archived], $operadora);

        $this->service()->sync($product->getKey());

        $this->assertSame(0, $this->gateway->callCount());
    }
}
