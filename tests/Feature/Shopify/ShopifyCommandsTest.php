<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\Enums\ProductStatus;
use App\Enums\SyncStatus;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\Product;
use App\Models\ShopifyInstallation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsSyncableProducts;
use Tests\Support\FakeShopifyGateway;
use Tests\TestCase;

/**
 * Comandos de consola de Shopify (RFC-0004).
 *
 * `shopify:sync` es la vía que se usa en local y en soporte, donde no hay un
 * worker de cola corriendo. Comparte el mismo servicio que el trabajo en cola,
 * así que estas pruebas comprueban sobre todo las guardas y los mensajes: que no
 * prometa un envío que luego rechazaría, y que un fallo se explique.
 */
class ShopifyCommandsTest extends TestCase
{
    use BuildsSyncableProducts;

    private FakeShopifyGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureShopify();
        $this->gateway = new FakeShopifyGateway;

        $this->app->instance(ShopifyProductGateway::class, $this->gateway);

        // Las pruebas usan QUEUE_CONNECTION=sync, así que el trabajo se ejecutaría
        // al encolarse y el envío ocurriría dos veces (una por la cola y otra por
        // la llamada explícita del comando). Se aísla para probar sólo la vía
        // síncrona, que es la que usa este comando.
        Queue::fake();

        // El comando atribuye el envío a un administrador técnico: en consola no
        // hay sesión. Sin él, la auditoría no sabría de quién es la acción.
        $this->admin = User::factory()->adminTecnico()->create();
    }

    // ------------------------------------------------------- shopify:check

    /**
     * Respuesta correcta de la comprobación de sólo lectura (RFC-0009).
     *
     * Es el documento `ConnectionCheck`: tienda, scopes concedidos y una página de
     * productos. Se centraliza aquí para que las pruebas de la pantalla y las del
     * comando comprueben el mismo contrato.
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function connectionCheckResponse(array $scopes = ShopifyInstallation::REQUIRED_SCOPES): array
    {
        return [
            'data' => [
                'shop' => [
                    'name' => 'Dies de Platja',
                    'myshopifyDomain' => 'dies-de-platja.myshopify.com',
                ],
                'currentAppInstallation' => [
                    'accessScopes' => array_map(static fn (string $scope): array => ['handle' => $scope], $scopes),
                ],
                'products' => [
                    'nodes' => [['id' => 'gid://shopify/Product/1']],
                ],
            ],
        ];
    }

    private function fakeConnectionCheck(array $scopes = ShopifyInstallation::REQUIRED_SCOPES): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->connectionCheckResponse($scopes))]);
    }

    public function test_check_avisa_cuando_la_aplicacion_no_esta_configurada(): void
    {
        config()->set('product-studio.shopify.api_key', '');
        config()->set('product-studio.shopify.api_secret', '');

        $this->artisan('shopify:check')
            ->expectsOutputToContain('La aplicación de Shopify no está configurada')
            ->expectsOutputToContain('SHOPIFY_API_SECRET')
            ->assertExitCode(1);
    }

    public function test_check_avisa_cuando_no_hay_tienda_conectada(): void
    {
        // La aplicación está configurada pero nadie ha instalado todavía.
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        ShopifyInstallation::query()->delete();

        $this->artisan('shopify:check')
            ->expectsOutputToContain('No hay ninguna tienda conectada')
            ->expectsOutputToContain('Conectar con Shopify')
            ->assertExitCode(1);
    }

    public function test_check_confirma_la_conexion_cuando_esta_configurado(): void
    {
        $this->fakeConnectionCheck();

        $this->artisan('shopify:check')
            ->expectsOutputToContain('Conexión correcta')
            ->expectsOutputToContain('Permisos concedidos')
            ->assertExitCode(0);
    }

    public function test_check_explica_un_token_invalido(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Invalid API key or access token']]], 401)]);

        $this->artisan('shopify:check')
            ->expectsOutputToContain('no es válido o no tiene permisos')
            ->assertExitCode(1);
    }

    public function test_check_indica_cuantas_fichas_estan_listas(): void
    {
        $this->syncableProduct();
        $this->fakeConnectionCheck();

        $this->artisan('shopify:check')
            ->expectsOutputToContain('Fichas listas para enviar: 1')
            ->assertExitCode(0);
    }

    public function test_check_avisa_si_faltan_permisos(): void
    {
        $this->fakeConnectionCheck(['read_products']);

        $this->artisan('shopify:check')
            ->expectsOutputToContain('write_products')
            ->assertExitCode(1);
    }

    public function test_check_nunca_imprime_el_token_ni_la_client_secret(): void
    {
        config()->set('product-studio.shopify.api_secret', 'shpss_marca-de-prueba');
        $this->fakeConnectionCheck();

        $this->artisan('shopify:check')
            ->doesntExpectOutputToContain('shpss_marca-de-prueba')
            ->doesntExpectOutputToContain('shpat_token-de-prueba')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------- shopify:sync

    public function test_sync_envia_la_ficha_y_deja_el_borrador_creado(): void
    {
        $product = $this->syncableProduct();

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference])
            ->expectsOutputToContain('BORRADOR')
            ->assertExitCode(0);

        $product->refresh();

        $this->assertSame(ProductStatus::ShopifyDraft, $product->status);
        $this->assertSame('gid://shopify/Product/1234567890', $product->shopify_product_gid);
        $this->assertNotNull($product->last_synced_at);

        $this->assertSame(1, $this->gateway->callCount());
    }

    public function test_sync_busca_la_ficha_sin_distinguir_mayusculas(): void
    {
        $product = $this->syncableProduct();

        $this->artisan('shopify:sync', ['reference' => mb_strtolower($product->internal_reference)])
            ->assertExitCode(0);

        $this->assertSame(1, $this->gateway->callCount());
    }

    public function test_sync_falla_si_la_referencia_no_existe(): void
    {
        $this->artisan('shopify:sync', ['reference' => 'NO-EXISTE'])
            ->expectsOutputToContain('No existe ninguna ficha')
            ->assertExitCode(1);

        $this->assertSame(0, $this->gateway->callCount());
    }

    public function test_sync_no_llama_a_shopify_si_hay_errores_bloqueantes(): void
    {
        // Ficha sin variantes ni foto ni contenido.
        $product = Product::factory()->approved()->create(['price' => 20.00]);

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference])
            ->expectsOutputToContain('errores bloqueantes')
            ->assertExitCode(1);

        $this->assertSame(0, $this->gateway->callCount());
    }

    /**
     * El `--dry-run` llegó a prometer un envío que la ejecución real rechazaba,
     * porque sólo miraba la validación de datos y no el estado de la ficha.
     */
    public function test_el_dry_run_no_promete_un_envio_que_el_flujo_real_rechazaria(): void
    {
        // Datos completos, pero en revisión: hace falta aprobarla antes.
        $product = $this->syncableProduct(['status' => ProductStatus::Review]);

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference, '--dry-run' => true])
            ->expectsOutputToContain('no puede enviarse desde el estado')
            ->assertExitCode(1);

        $this->assertSame(0, $this->gateway->callCount());
    }

    public function test_el_dry_run_no_llama_a_shopify_ni_cambia_la_ficha(): void
    {
        $product = $this->syncableProduct();

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference, '--dry-run' => true])
            ->expectsOutputToContain('no se ha llamado a Shopify')
            ->assertExitCode(0);

        $this->assertSame(0, $this->gateway->callCount());

        $product->refresh();
        $this->assertSame(ProductStatus::Approved, $product->status);
        $this->assertNull($product->shopify_product_gid);
    }

    public function test_el_dry_run_anuncia_la_actualizacion_si_ya_hay_producto(): void
    {
        $product = $this->syncableProduct([
            'shopify_product_gid' => 'gid://shopify/Product/777',
        ]);

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference, '--dry-run' => true])
            ->expectsOutputToContain('Se actualizaría el producto')
            ->assertExitCode(0);

        $this->assertSame(0, $this->gateway->callCount());
    }

    public function test_sync_explica_un_fallo_con_su_referencia_de_soporte(): void
    {
        $product = $this->syncableProduct();

        $this->gateway->alwaysFailWith = ShopifyRequestFailed::permanent(
            'El token de Shopify no es válido o no tiene permisos suficientes. Avisa al administrador técnico.',
            'unauthorized',
        );

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference])
            ->expectsOutputToContain('Referencia de soporte')
            ->assertExitCode(1);

        // El intento queda registrado aunque el comando falle.
        $this->assertSame(1, $product->syncAttempts()->count());
        $this->assertSame(SyncStatus::Failed, $product->syncAttempts()->first()->status);
        $this->assertSame(ProductStatus::SyncFailed, $product->refresh()->status);
    }

    public function test_sync_nunca_publica(): void
    {
        $product = $this->syncableProduct();

        $this->artisan('shopify:sync', ['reference' => $product->internal_reference])->assertExitCode(0);

        // El payload que llegó al conector iba como borrador.
        $this->assertSame('DRAFT', $this->gateway->lastPayload()?->status);
        $this->assertNotSame(ProductStatus::Published, $product->refresh()->status);
    }
}
