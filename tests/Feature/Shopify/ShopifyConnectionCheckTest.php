<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\ShopifyInstallation;
use App\Services\Shopify\ShopifyConnectionChecker;
use App\Services\Shopify\ShopifyGraphQlClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Comprobación de sólo lectura y autenticación del transporte (RFC-0009).
 *
 * Cubre las dos afirmaciones que sostienen la corrección de arquitectura:
 *
 * 1. La cabecera `X-Shopify-Access-Token` se rellena **sólo** con el token de la
 *    instalación, nunca con una credencial del entorno.
 * 2. La comprobación de conexión es de sólo lectura y responde a las cuatro
 *    preguntas que pidió la petición.
 */
class ShopifyConnectionCheckTest extends TestCase
{
    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function response(array $scopes = ShopifyInstallation::REQUIRED_SCOPES, ?string $domain = null): array
    {
        return [
            'data' => [
                'shop' => [
                    'name' => 'Dies de Platja',
                    'myshopifyDomain' => $domain ?? 'dies-de-platja.myshopify.com',
                ],
                'currentAppInstallation' => [
                    'accessScopes' => array_map(static fn (string $s): array => ['handle' => $s], $scopes),
                ],
                'products' => ['nodes' => [['id' => 'gid://shopify/Product/1']]],
            ],
        ];
    }

    private function installation(array $attributes = []): ShopifyInstallation
    {
        return ShopifyInstallation::factory()->create($attributes);
    }

    // ------------------------------------------------------ autenticación del transporte

    public function test_la_cabecera_lleva_el_token_de_la_instalacion(): void
    {
        $this->installation(['access_token' => 'shpat_token-de-la-instalacion']);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        app(ShopifyConnectionChecker::class)->check();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Shopify-Access-Token', 'shpat_token-de-la-instalacion'));
    }

    public function test_sin_instalacion_el_conector_no_esta_configurado(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->assertFalse(app(ShopifyGraphQlClient::class)->isConfigured());

        try {
            app(ShopifyGraphQlClient::class)->query('X', 'query X { shop { name } }');
            $this->fail('Debería haber fallado.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('not_configured', $failure->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_una_instalacion_con_shpss_no_esta_configurada(): void
    {
        // Es el error de arquitectura que motivó la RFC: la client secret no es un
        // access token. Debe detectarse en local, sin llegar a Shopify.
        $this->installation(['access_token' => 'shpss_secreto-de-prueba']);

        Http::fake();
        Http::preventStrayRequests();

        $this->assertFalse(app(ShopifyGraphQlClient::class)->isConfigured());

        try {
            app(ShopifyGraphQlClient::class)->query('X', 'query X { shop { name } }');
            $this->fail('Debería haber fallado.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('client_secret_is_not_an_access_token', $failure->errorCode);
            $this->assertStringContainsString('shpss_', $failure->getMessage());
            $this->assertStringContainsString('no un access token', $failure->getMessage());
        }

        // Lo importante: no se ha enviado nada con esa credencial.
        Http::assertNothingSent();
    }

    public function test_una_credencial_con_prefijo_desconocido_se_rechaza(): void
    {
        $this->installation(['access_token' => 'credencial-sin-prefijo']);

        Http::fake();
        Http::preventStrayRequests();

        try {
            app(ShopifyGraphQlClient::class)->query('X', 'query X { shop { name } }');
            $this->fail('Debería haber fallado.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('invalid_access_token', $failure->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_el_token_nunca_se_serializa(): void
    {
        $installation = $this->installation(['access_token' => 'shpat_muy-secreto']);

        $this->assertArrayNotHasKey('access_token', $installation->toArray());
        $this->assertStringNotContainsString('shpat_muy-secreto', json_encode($installation) ?: '');
        $this->assertStringNotContainsString('shpat_muy-secreto', (string) $installation->toJson());
    }

    public function test_el_endpoint_usa_el_dominio_de_la_instalacion(): void
    {
        $this->installation(['shop_domain' => 'dies-de-platja.myshopify.com']);

        $this->assertSame(
            'https://dies-de-platja.myshopify.com/admin/api/2026-07/graphql.json',
            app(ShopifyGraphQlClient::class)->endpoint(),
        );
    }

    // --------------------------------------------------- comprobación de sólo lectura

    public function test_la_comprobacion_pasa_con_token_scopes_y_productos(): void
    {
        $installation = $this->installation();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        $this->assertTrue($check->passed);
        $this->assertTrue($check->domainIsValid());
        $this->assertTrue($check->hasAllScopes());
        $this->assertTrue($check->productsReadable);
        $this->assertSame('Dies de Platja', $check->shopName);

        // Las cuatro preguntas que pidió la petición, con su resultado.
        $this->assertCount(4, $check->asRows());
    }

    public function test_la_comprobacion_no_envia_ninguna_mutacion(): void
    {
        $this->installation();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        app(ShopifyConnectionChecker::class)->check();

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            // Una sola consulta, y ninguna palabra clave de mutación.
            return str_contains($body, '"query"')
                && ! str_contains($body, '"mutation"')
                && ! str_contains($body, 'productSet')
                && ! str_contains($body, 'fileCreate')
                && ! str_contains($body, 'stagedUploadsCreate');
        });

        Http::assertSentCount(1);
    }

    public function test_la_comprobacion_devuelve_los_scopes_concedidos(): void
    {
        $installation = $this->installation();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response(['read_products', 'write_products']))]);

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        $this->assertSame(['read_products', 'write_products'], $check->grantedScopes);
        $this->assertSame(['read_files', 'write_files'], $check->missingScopes);
        $this->assertFalse($check->hasAllScopes());
        $this->assertFalse($check->passed);
    }

    public function test_la_comprobacion_detecta_que_la_tienda_no_coincide(): void
    {
        $installation = $this->installation();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response(domain: 'otra-tienda.myshopify.com'))]);

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        $this->assertFalse($check->domainIsValid());
        $this->assertFalse($check->passed);
    }

    public function test_la_comprobacion_no_lanza_si_shopify_falla(): void
    {
        $installation = $this->installation();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Invalid API key or access token']]], 401)]);

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        // Un fallo de conexión es un resultado que la pantalla muestra, no una
        // excepción que rompa el panel.
        $this->assertFalse($check->passed);
        $this->assertNotNull($check->errorMessage);

        // Y queda registrado para la siguiente visita.
        $this->assertNotNull($installation->refresh()->last_checked_at);
        $this->assertNotNull($installation->last_check_error);
    }

    public function test_la_comprobacion_registra_la_marca_de_tiempo_al_pasar(): void
    {
        $installation = $this->installation(['last_checked_at' => null, 'last_check_error' => 'antiguo']);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        app(ShopifyConnectionChecker::class)->check($installation);

        $installation->refresh();

        $this->assertNotNull($installation->last_checked_at);
        $this->assertNull($installation->last_check_error);
    }

    public function test_sin_instalacion_la_comprobacion_lo_dice_sin_llamar_a_shopify(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $check = app(ShopifyConnectionChecker::class)->check();

        $this->assertFalse($check->passed);

        Http::assertNothingSent();
    }

    public function test_un_dominio_no_myshopify_se_detecta_sin_llamar_a_shopify(): void
    {
        $installation = $this->installation(['shop_domain' => 'tienda.example.com']);

        Http::fake();
        Http::preventStrayRequests();

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        $this->assertFalse($check->passed);
        $this->assertFalse($check->domainIsValid());

        // Se detecta por formato, así que no hace falta molestar a Shopify.
        Http::assertNothingSent();
    }
}
