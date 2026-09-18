<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Models\ActivityLog;
use App\Models\ShopifyInstallation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Callback de instalación OAuth, de extremo a extremo (RFC-0009 §6).
 *
 * El servicio por separado ya está probado en `ShopifyOAuthTest`; aquí se comprueba
 * el recorrido completo por HTTP, que es donde aparecen los errores de integración:
 * que el `state` de la sesión y el devuelto por Shopify viajen de verdad, que un
 * `state` manipulado no instale nada y que un fallo no deje una instalación a medias.
 *
 * El callback **no** se prueba sin sesión a propósito: llega por una redirección del
 * navegador de la persona que inició la instalación, así que sí lleva su sesión. Su
 * autenticidad no depende de eso, sino del `state` y del `hmac`.
 */
class ShopifyOAuthCallbackTest extends TestCase
{
    private const STATE = 'estado-de-prueba-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', 'dies-de-platja.myshopify.com');
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function sign(array $params): array
    {
        ksort($params, SORT_STRING);

        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $params['hmac'] = hash_hmac('sha256', implode('&', $pairs), 'shpss_secreto-de-prueba');

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function callbackQuery(string $state = self::STATE): array
    {
        return $this->sign([
            'code' => 'codigo-de-prueba',
            'shop' => 'dies-de-platja.myshopify.com',
            'state' => $state,
            'timestamp' => '1700000000',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeResponse(): array
    {
        return [
            'access_token' => 'shpat_token-offline-de-prueba',
            'scope' => 'read_products,write_products,read_files,write_files',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionCheckResponse(): array
    {
        return [
            'data' => [
                'shop' => [
                    'name' => 'Dies de Platja',
                    'myshopifyDomain' => 'dies-de-platja.myshopify.com',
                ],
                'currentAppInstallation' => [
                    'accessScopes' => [
                        ['handle' => 'read_products'],
                        ['handle' => 'write_products'],
                        ['handle' => 'read_files'],
                        ['handle' => 'write_files'],
                    ],
                ],
                'products' => ['nodes' => []],
            ],
        ];
    }

    /**
     * Doble de HTTP que responde al canje y a la comprobación de sólo lectura.
     */
    private function fakeShopify(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/admin/oauth/access_token' => Http::response($this->exchangeResponse()),
            '*/admin/api/*/graphql.json' => Http::response($this->connectionCheckResponse()),
        ]);
    }

    // ------------------------------------------------------------------ camino feliz

    public function test_el_callback_instala_la_tienda_y_guarda_el_token_cifrado(): void
    {
        $this->fakeShopify();

        $response = $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        // Termina en la pantalla de conexión, nunca en una respuesta con el token.
        $response->assertRedirect('/admin/shopify-connection');

        $installation = ShopifyInstallation::current();

        $this->assertNotNull($installation);
        $this->assertSame('dies-de-platja.myshopify.com', $installation->shop_domain);
        $this->assertSame('shpat_token-offline-de-prueba', $installation->access_token);
        $this->assertSame(ShopifyInstallation::REQUIRED_SCOPES, $installation->grantedScopes());

        // Se ha comprobado de inmediato, para no descubrir un problema en el primer
        // envío de una ficha.
        $this->assertNotNull($installation->last_checked_at);
    }

    public function test_el_callback_consume_el_estado_para_que_no_se_reutilice(): void
    {
        $this->fakeShopify();

        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        // El estado se retira de la sesión al usarlo: un enlace de callback no puede
        // valer dos veces.
        $this->assertNull(session('shopify.oauth.state'));

        ShopifyInstallation::query()->delete();

        // Y la segunda vez se rechaza, sin canjear nada.
        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        $this->assertSame(0, ShopifyInstallation::query()->count());
    }

    // ------------------------------------------------------------------- rechazos

    public function test_el_callback_rechaza_un_estado_manipulado_sin_canjear_el_codigo(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->exchangeResponse())]);

        // La sesión tiene el estado bueno, pero la URL trae otro.
        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery('estado-manipulado')));

        // Lo esencial: no se ha canjeado ningún código ni se ha instalado nada.
        $this->assertSame(0, ShopifyInstallation::query()->count());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'access_token'));
    }

    public function test_el_callback_rechaza_una_firma_invalida_sin_canjear_el_codigo(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->exchangeResponse())]);

        $query = $this->callbackQuery();
        // Firma manipulada: el estado es correcto, pero el `hmac` no.
        $query['hmac'] = str_repeat('b', 64);

        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($query));

        $this->assertSame(0, ShopifyInstallation::query()->count());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'access_token'));
    }

    public function test_el_callback_rechaza_si_la_sesion_no_tiene_estado(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->exchangeResponse())]);

        // Sin estado en la sesión no hay nada contra lo que comparar. Es lo que
        // ocurre si alguien abre el callback directamente.
        $this->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        $this->assertSame(0, ShopifyInstallation::query()->count());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'access_token'));
    }

    public function test_el_callback_no_canjea_si_la_tienda_no_es_la_esperada(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->exchangeResponse())]);

        $query = $this->sign([
            'code' => 'codigo-de-prueba',
            'shop' => 'otra-tienda.myshopify.com',
            'state' => self::STATE,
            'timestamp' => '1700000000',
        ]);

        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($query));

        $this->assertSame(0, ShopifyInstallation::query()->count());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'access_token'));
    }

    public function test_el_callback_no_guarda_nada_si_el_canje_falla(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'invalid_request'], 401)]);

        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        // Un canje fallido no puede dejar una instalación sin token utilizable.
        $this->assertSame(0, ShopifyInstallation::query()->count());
    }

    public function test_la_respuesta_del_callback_no_contiene_ningun_secreto(): void
    {
        $this->fakeShopify();

        $response = $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        $location = (string) $response->headers->get('Location');

        // Ni el token ni la client secret pueden viajar en la redirección: una URL
        // queda en el historial del navegador y en los logs del servidor.
        $this->assertStringNotContainsString('shpat_token-offline-de-prueba', $location);
        $this->assertStringNotContainsString('shpss_secreto-de-prueba', $location);
        $this->assertStringNotContainsString('code=', $location);
    }

    public function test_el_callback_se_audita_sin_registrar_credenciales(): void
    {
        $this->fakeShopify();

        $this->withSession(['shopify.oauth.state' => self::STATE])
            ->actingAs($this->twoFactorAdmin())
            ->get('/admin/shopify/callback?'.http_build_query($this->callbackQuery()));

        // La auditoría se guarda redactada: se comprueba sobre lo persistido, no
        // sobre lo que quiso registrarse.
        $log = ActivityLog::query()->where('event', 'shopify_connected')->first();

        $this->assertNotNull($log);

        $raw = (string) json_encode($log->properties).(string) json_encode($log->changes_json);

        $this->assertStringNotContainsString('shpat_token-offline-de-prueba', $raw);
        $this->assertStringNotContainsString('shpss_secreto-de-prueba', $raw);
    }
}
