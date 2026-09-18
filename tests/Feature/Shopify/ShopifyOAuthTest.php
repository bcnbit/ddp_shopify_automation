<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Exceptions\Shopify\ShopifyOAuthFailed;
use App\Models\ShopifyInstallation;
use App\Services\Shopify\ShopifyOAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Instalación por OAuth (RFC-0009).
 *
 * El punto central de estas pruebas es la **seguridad del callback**: el callback
 * lo puede invocar cualquiera que conozca la URL, así que su autenticidad depende
 * de que se comprueben `state` y `hmac` antes de canjear el código. Una prueba que
 * sólo comprobara el camino feliz no protegería nada.
 */
class ShopifyOAuthTest extends TestCase
{
    private function service(): ShopifyOAuthService
    {
        return app(ShopifyOAuthService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', 'dies-de-platja.myshopify.com');
    }

    /**
     * Firma un conjunto de parámetros como lo hace Shopify.
     *
     * Se replica el algoritmo en la prueba de forma independiente al servicio: si
     * se usara un helper del propio servicio, una firma mal calculada pasaría las
     * pruebas igualmente. Aquí se escribe según la documentación vigente (quitar
     * `hmac`, ordenar alfabéticamente como `k=v`, unir con `&` y HMAC-SHA256 hex).
     *
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function sign(array $params, string $secret = 'shpss_secreto-de-prueba'): array
    {
        ksort($params, SORT_STRING);

        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $params['hmac'] = hash_hmac('sha256', implode('&', $pairs), $secret);

        return $params;
    }

    private function validQuery(string $state): array
    {
        return $this->sign([
            'code' => 'codigo-de-prueba',
            'shop' => 'dies-de-platja.myshopify.com',
            'state' => $state,
            'timestamp' => '1700000000',
        ]);
    }

    // ------------------------------------------------------------- URL de autorización

    public function test_la_url_de_autorizacion_pide_los_scopes_minimos_y_un_token_offline(): void
    {
        $url = $this->service()->authorizationUrl('dies-de-platja.myshopify.com', 'estado-de-prueba');

        $this->assertStringStartsWith('https://dies-de-platja.myshopify.com/admin/oauth/authorize?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('client-id-de-prueba', $query['client_id']);
        $this->assertSame('estado-de-prueba', $query['state']);

        // Token offline: se omiten `grant_options[]`. Añadirlos con `per-user`
        // devolvería un token online, que caduca con la sesión de la persona.
        $this->assertArrayNotHasKey('grant_options', $query);

        // Los cuatro scopes necesarios, y ninguno de más.
        $requested = explode(',', (string) $query['scope']);
        sort($requested);

        $expected = ShopifyInstallation::REQUIRED_SCOPES;
        sort($expected);

        $this->assertSame($expected, $requested);
        $this->assertSame(['read_files', 'read_products', 'write_files', 'write_products'], $requested);
    }

    public function test_la_url_de_autorizacion_rechaza_un_dominio_que_no_es_myshopify(): void
    {
        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->authorizationUrl('tienda.example.com', 'estado');
    }

    public function test_la_url_de_autorizacion_acepta_un_dominio_pegado_con_https_y_barra(): void
    {
        $url = $this->service()->authorizationUrl('https://Dies-De-Platja.myshopify.com/', 'x');

        $this->assertStringStartsWith('https://dies-de-platja.myshopify.com/admin/oauth/authorize?', $url);
    }

    public function test_la_app_no_configurada_da_un_mensaje_util(): void
    {
        config()->set('product-studio.shopify.api_key', '');
        config()->set('product-studio.shopify.api_secret', '');

        $this->expectException(ShopifyOAuthFailed::class);
        $this->expectExceptionMessage('SHOPIFY_API_KEY');

        $this->service()->authorizationUrl('dies-de-platja.myshopify.com', 'x');
    }

    // -------------------------------------------------------------------- callback

    public function test_el_callback_acepta_una_firma_hmac_valida(): void
    {
        $query = $this->validQuery('estado-ok');

        $shop = $this->service()->verifyCallback($query, 'estado-ok');

        $this->assertSame('dies-de-platja.myshopify.com', $shop);
    }

    public function test_el_callback_rechaza_una_firma_hmac_invalida(): void
    {
        $query = $this->validQuery('estado-ok');
        $query['hmac'] = str_repeat('a', 64);

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, 'estado-ok');
    }

    public function test_el_callback_rechaza_una_firma_calculada_con_otra_secret(): void
    {
        // Firma correcta según el algoritmo, pero con una credencial distinta: es el
        // caso de alguien que conoce la URL de callback pero no la client secret.
        $query = $this->sign([
            'code' => 'codigo-de-prueba',
            'shop' => 'dies-de-platja.myshopify.com',
            'state' => 'estado-ok',
        ], 'shpss_secret-de-otra-aplicacion');

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, 'estado-ok');
    }

    public function test_el_callback_rechaza_un_state_que_no_coincide(): void
    {
        $query = $this->validQuery('estado-recibido');

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, 'estado-esperado');
    }

    public function test_el_callback_rechaza_un_state_ausente(): void
    {
        $query = $this->validQuery('estado-ok');
        unset($query['state']);

        // Se reutiliza una firma ya calculada: sin `state` la comprobación debe
        // fallar antes incluso de mirar la firma.
        $query = $this->sign($query);

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, null);
    }

    public function test_el_callback_rechaza_un_dominio_que_no_es_myshopify(): void
    {
        $query = $this->sign([
            'code' => 'codigo',
            'shop' => 'tienda.example.com',
            'state' => 'estado-ok',
        ]);

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, 'estado-ok');
    }

    public function test_el_callback_rechaza_una_instalacion_en_otra_tienda(): void
    {
        // Firma válida para otra tienda real de Shopify: pasa el `hmac` y el formato
        // de dominio, así que lo único que lo detiene es la guarda del dominio
        // esperado. Sin ella, esta instalación sustituiría a la de la tienda buena.
        $query = $this->sign([
            'code' => 'codigo',
            'shop' => 'otra-tienda.myshopify.com',
            'state' => 'estado-ok',
        ]);

        $this->expectException(ShopifyOAuthFailed::class);

        $this->service()->verifyCallback($query, 'estado-ok');
    }

    // ------------------------------------------------------------------ intercambio

    public function test_el_intercambio_guarda_el_token_cifrado_y_no_en_claro(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'access_token' => 'shpat_token-offline-de-prueba',
            'scope' => 'read_products,write_products,read_files,write_files',
        ])]);

        $service = $this->service();
        $granted = $service->exchangeCodeForToken('dies-de-platja.myshopify.com', 'codigo');
        $installation = $service->store('dies-de-platja.myshopify.com', $granted);

        $this->assertSame('shpat_token-offline-de-prueba', $installation->access_token);

        // Lo que hay **en la fila** no es el token: es su criptograma. Es la prueba
        // de que el cast `encrypted` está aplicado y no sólo declarado.
        $raw = DB::table('shopify_installations')->where('id', $installation->id)->value('access_token');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('shpat_token-offline-de-prueba', $raw);
        $this->assertNotSame('shpat_token-offline-de-prueba', $raw);

        // Y los scopes quedan registrados para poder contrastarlos.
        $this->assertSame(ShopifyInstallation::REQUIRED_SCOPES, $installation->grantedScopes());
        $this->assertSame([], $installation->missingScopes());
    }

    public function test_el_intercambio_envia_las_credenciales_documentadas(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'access_token' => 'shpat_token-de-prueba',
            'scope' => 'read_products,write_products,read_files,write_files',
        ])]);

        $this->service()->exchangeCodeForToken('dies-de-platja.myshopify.com', 'codigo-xyz');

        // El canje va contra el endpoint documentado y por formulario.
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://dies-de-platja.myshopify.com/admin/oauth/access_token'
                && $request['client_id'] === 'client-id-de-prueba'
                && $request['code'] === 'codigo-xyz';
        });

        // Un token no expirable no debe pedir `expiring=1`.
        Http::assertNotSent(fn (Request $request): bool => ($request['expiring'] ?? null) === '1');
    }

    public function test_un_shpss_se_rechaza_como_access_token_con_un_mensaje_explicito(): void
    {
        Http::preventStrayRequests();
        // Shopify no devolvería esto nunca; se simula para comprobar la guarda, que es
        // justo el error de arquitectura que motivó esta RFC.
        Http::fake(['*' => Http::response([
            'access_token' => 'shpss_secreto-de-prueba',
            'scope' => 'read_products',
        ])]);

        try {
            $this->service()->exchangeCodeForToken('dies-de-platja.myshopify.com', 'codigo');
            $this->fail('Debería haber rechazado la client secret.');
        } catch (ShopifyOAuthFailed $failure) {
            $this->assertStringContainsString('shpss_', $failure->getMessage());
            $this->assertStringContainsString('no un access token', $failure->getMessage());
        }

        // Y no puede haber quedado nada guardado.
        $this->assertSame(0, ShopifyInstallation::query()->count());
    }

    public function test_si_falla_el_intercambio_no_se_guarda_ninguna_instalacion(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'invalid_request'], 401)]);

        try {
            $this->service()->exchangeCodeForToken('dies-de-platja.myshopify.com', 'codigo-malo');
            $this->fail('Debería haber fallado.');
        } catch (ShopifyOAuthFailed) {
            // Correcto: un fallo de canje no deja instalación a medias.
        }

        $this->assertSame(0, ShopifyInstallation::query()->count());
    }

    public function test_el_error_del_intercambio_no_filtra_la_client_secret(): void
    {
        Http::preventStrayRequests();
        // Un error de Shopify que repitiese la credencial enviada no debe llegar así
        // al mensaje: el redactor tiene que enmascararla.
        Http::fake(['*' => Http::response(['error' => 'client_secret=shpss_secreto-de-prueba inválido'], 401)]);

        try {
            $this->service()->exchangeCodeForToken('dies-de-platja.myshopify.com', 'codigo');
            $this->fail('Debería haber fallado.');
        } catch (ShopifyOAuthFailed $failure) {
            $this->assertStringNotContainsString('shpss_secreto-de-prueba', $failure->getMessage());
        }
    }

    // ------------------------------------------------------- reemplazo de instalación

    public function test_reinstalar_reemplaza_la_instalacion_en_lugar_de_acumular(): void
    {
        ShopifyInstallation::factory()->forDomain('vieja-tienda.myshopify.com')->create();

        $service = $this->service();
        $service->store('dies-de-platja.myshopify.com', [
            'access_token' => 'shpat_token-nuevo',
            'scopes' => ShopifyInstallation::REQUIRED_SCOPES,
            'is_expiring' => false,
            'expires_at' => null,
        ]);

        // Una sola instalación activa: la vieja no debe sobrevivir.
        $this->assertSame(1, ShopifyInstallation::query()->count());
        $this->assertSame('dies-de-platja.myshopify.com', ShopifyInstallation::current()?->shop_domain);
    }

    public function test_los_tokens_de_prueba_usan_el_prefijo_correcto(): void
    {
        // Si la fábrica usara un token sin `shpat_`, las pruebas de RFC-0004 pasarían
        // por el camino de «token inválido» y no ejercitarían el transporte.
        $installation = ShopifyInstallation::factory()->create();

        $this->assertTrue($installation->hasUsableToken());
        $this->assertTrue(ShopifyInstallation::isAccessToken($installation->access_token));
    }
}
