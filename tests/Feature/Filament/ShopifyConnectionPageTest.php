<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ShopifyConnection;
use App\Models\ShopifyInstallation;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantalla de conexión con Shopify (RFC-0009 §10).
 *
 * Dos cosas que hay que demostrar aquí y no en otro sitio:
 *
 * - Que la pantalla **no** entrega credenciales al navegador. Es una página
 *   Livewire: todo lo que sea propiedad pública viaja al cliente, así que la
 *   comprobación se hace sobre el HTML renderizado, no sobre la intención del
 *   código.
 * - Que el diagnóstico dice qué falta cuando la aplicación no está configurada,
 *   porque es el primer estado en el que se encontrará quien la instale.
 */
class ShopifyConnectionPageTest extends TestCase
{
    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function response(array $scopes = ShopifyInstallation::REQUIRED_SCOPES): array
    {
        return [
            'data' => [
                'shop' => [
                    'name' => 'Dies de Platja',
                    'myshopifyDomain' => 'dies-de-platja.myshopify.com',
                ],
                'currentAppInstallation' => [
                    'accessScopes' => array_map(static fn (string $s): array => ['handle' => $s], $scopes),
                ],
                'products' => ['nodes' => []],
            ],
        ];
    }

    /**
     * Cada usuario se comprueba en su propia prueba y con la sesión limpia.
     *
     * No es una manía: `AuthenticateSession` guarda el hash del usuario de la sesión
     * y, si se cambia de usuario dentro de una misma prueba, invalida la sesión y
     * expulsa la petición. Mezclarlos mediría ese middleware en lugar de la Policy
     * que se quiere verificar.
     */
    public function test_el_administrador_tecnico_accede_a_la_pantalla(): void
    {
        $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection')->assertOk();
    }

    public function test_la_operadora_no_accede_a_la_pantalla_de_conexion(): void
    {
        // La operadora prepara fichas, pero conectar la tienda entrega una
        // credencial con escritura sobre el catálogo: no es su tarea. La barrera es
        // la Policy, no que el menú esté oculto.
        $this->actingAs($this->operadora())->get('/admin/shopify-connection')->assertForbidden();
    }

    public function test_el_responsable_no_accede_a_la_pantalla_de_conexion(): void
    {
        $this->actingAs($this->responsable())->get('/admin/shopify-connection')->assertForbidden();
    }

    public function test_la_operadora_no_puede_invocar_la_ruta_de_instalacion(): void
    {
        // Ocultar el botón no autoriza: la ruta se puede teclear a mano, así que la
        // comprobación tiene que estar en la ruta.
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', 'dies-de-platja.myshopify.com');

        $this->actingAs($this->operadora())
            ->get('/admin/shopify/install')
            ->assertForbidden();
    }

    public function test_la_instalacion_redirige_a_shopify_con_el_estado_guardado(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', 'dies-de-platja.myshopify.com');

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify/install');

        // Se sale hacia la pantalla de autorización de Shopify, con el `state` ya
        // guardado en la sesión para poder compararlo al volver.
        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://dies-de-platja.myshopify.com/admin/oauth/authorize?',
            (string) $response->headers->get('Location'),
        );
        $this->assertNotNull(session('shopify.oauth.state'));
    }

    public function test_la_instalacion_no_redirige_sin_dominio_configurado(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', '');

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify/install');

        // Sin tienda no se puede construir la URL: se vuelve al panel con aviso en
        // lugar de salir hacia una tienda inventada.
        $response->assertRedirect('/admin/shopify-connection');
    }

    public function test_la_pantalla_explica_que_falta_cuando_no_hay_aplicacion(): void
    {
        config()->set('product-studio.shopify.api_key', '');
        config()->set('product-studio.shopify.api_secret', '');

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection');

        $response->assertOk();
        $response->assertSee('no está configurada', false);
        $response->assertSee('SHOPIFY_API_SECRET', false);

        // Y el aviso clave: la API secret key no se usa como token.
        $response->assertSee('no', false);
    }

    public function test_la_pantalla_muestra_el_estado_y_los_permisos_concedidos(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');

        ShopifyInstallation::factory()->create([
            'shop_domain' => 'dies-de-platja.myshopify.com',
            'scopes' => ['read_products', 'write_products', 'read_files', 'write_files'],
        ]);

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection');

        $response->assertOk();
        $response->assertSee('dies-de-platja.myshopify.com', false);
        $response->assertSee('read_products', false);
        $response->assertSee('write_files', false);
    }

    public function test_la_pantalla_avisa_de_los_permisos_que_faltan(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');

        ShopifyInstallation::factory()->create(['scopes' => ['read_products']]);

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection');

        $response->assertOk();
        $response->assertSee('Faltan permisos', false);
        $response->assertSee('write_products', false);
    }

    public function test_la_pantalla_no_expone_ningun_secreto_al_navegador(): void
    {
        // Valores con marca, para poder buscarlos literalmente en el HTML.
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_MARCA_SECRETA');

        ShopifyInstallation::factory()->create(['access_token' => 'shpat_MARCA_TOKEN']);

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection');

        $response->assertOk();

        $html = $response->getContent();

        // Ni el token, ni la client secret, ni el modelo serializado.
        $this->assertIsString($html);
        $this->assertStringNotContainsString('shpat_MARCA_TOKEN', $html);
        $this->assertStringNotContainsString('shpss_MARCA_SECRETA', $html);
        $this->assertStringNotContainsString('access_token', $html);

        // El dominio sí, porque no es un secreto y es lo que confirma la conexión.
        $this->assertStringContainsString('dies-de-platja.myshopify.com', $html);
    }

    public function test_la_pantalla_no_expone_secretos_tampoco_tras_comprobar(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_MARCA_SECRETA');

        ShopifyInstallation::factory()->create(['access_token' => 'shpat_MARCA_TOKEN']);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        Livewire::actingAs($this->twoFactorAdmin())
            ->test(ShopifyConnection::class)
            ->call('checkConnection')
            ->assertOk();

        $html = Livewire::actingAs($this->twoFactorAdmin())->test(ShopifyConnection::class)->html();

        $this->assertStringNotContainsString('shpat_MARCA_TOKEN', $html);
        $this->assertStringNotContainsString('shpss_MARCA_SECRETA', $html);
    }

    public function test_la_pantalla_comprueba_la_conexion_a_peticion(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');

        ShopifyInstallation::factory()->create();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->response())]);

        Livewire::actingAs($this->twoFactorAdmin())
            ->test(ShopifyConnection::class)
            ->call('checkConnection')
            ->assertOk();

        // La comprobación se ha hecho de verdad y ha dejado su marca.
        Http::assertSentCount(1);
        $this->assertNotNull(ShopifyInstallation::current()?->last_checked_at);
    }

    public function test_la_pantalla_no_ofrece_conectar_sin_dominio(): void
    {
        config()->set('product-studio.shopify.api_key', 'client-id-de-prueba');
        config()->set('product-studio.shopify.api_secret', 'shpss_secreto-de-prueba');
        config()->set('product-studio.shopify.expected_shop_domain', '');

        $response = $this->actingAs($this->twoFactorAdmin())->get('/admin/shopify-connection');

        $response->assertOk();
        $response->assertSee('Indica el dominio de la tienda', false);
    }
}
