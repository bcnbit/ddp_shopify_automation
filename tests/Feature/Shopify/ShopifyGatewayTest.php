<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\DataObjects\Shopify\ShopifyProductPayload;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\ShopifyInstallation;
use App\Services\Shopify\ShopifyProductGatewayImpl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSyncableProducts;
use Tests\TestCase;

/**
 * Conector con Shopify (RFC-0004).
 *
 * Ninguna prueba toca la red: se usa un doble de HTTP. Lo que se comprueba es
 * el contrato —estado borrador, idempotencia, ALT y traducción de errores—, no
 * el proveedor.
 */
class ShopifyGatewayTest extends TestCase
{
    use BuildsSyncableProducts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureShopify();
    }

    private function gateway(): ShopifyProductGateway
    {
        return app(ShopifyProductGatewayImpl::class);
    }

    public function test_envia_el_producto_siempre_como_borrador(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->body(), 'productSet')) {
                return false;
            }

            // Es la garantía del RFC-0000: ninguna ruta puede publicar.
            return ($this->graphQlVariables($request)['input']['status'] ?? null) === 'DRAFT';
        });
    }

    public function test_envia_titulo_descripcion_handle_tags_y_seo(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            $input = $this->graphQlVariables($request)['input'] ?? [];

            return ($input['title'] ?? null) === 'Camiseta Marina de algodón'
                && ($input['handle'] ?? null) === 'camiseta-marina'
                && str_contains((string) ($input['descriptionHtml'] ?? ''), 'corte regular')
                && in_array('camiseta', $input['tags'] ?? [], true)
                && ($input['seo']['title'] ?? null) === 'Camiseta Marina de algodón | Dies de Platja';
        });
    }

    public function test_declara_las_opciones_y_las_variantes_con_su_sku_y_precio(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            $input = $this->graphQlVariables($request)['input'] ?? [];

            $optionNames = array_column($input['productOptions'] ?? [], 'name');
            $skus = array_column($input['variants'] ?? [], 'sku');

            return $optionNames === ['Color', 'Talla']
                && in_array('DDP-1001-ROJO-M', $skus, true)
                && ($input['variants'][0]['optionValues'][0]['name'] ?? null) === 'Rojo';
        });
    }

    /**
     * Shopify numera las variantes desde 1 y rechaza cualquier posición fuera de
     * `1..N` con «Variant position must be between 1 and the number of variants on
     * the product». En local la posición arranca en 0, así que reenviarla tal cual
     * hacía fallar toda sincronización con variantes.
     */
    public function test_numera_las_variantes_desde_uno_para_shopify(): void
    {
        $product = $this->syncableProduct();

        // La ficha de prueba crea las variantes en 0 y 1: exactamente el caso que
        // Shopify rechaza.
        $this->assertSame([0, 1], $product->variants->pluck('position')->all());

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            $variants = $this->graphQlVariables($request)['input']['variants'] ?? [];

            $positions = array_map(
                static fn (array $variant): int => (int) ($variant['position'] ?? 0),
                $variants,
            );

            return $positions === [1, 2];
        });
    }

    /**
     * Una variante borrada deja huecos en la numeración local. Shopify exige que
     * las posiciones sean contiguas dentro de `1..N`, así que no basta con sumar
     * uno al valor local.
     */
    public function test_renumera_las_posiciones_contiguas_aunque_haya_huecos(): void
    {
        $product = $this->syncableProduct();

        $product->variants()->where('sku', 'DDP-1001-ROJO-M')->update(['position' => 0]);
        $product->variants()->where('sku', 'DDP-1001-ROJO-L')->update(['position' => 7]);

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product->refresh()));

        Http::assertSent(function (Request $request): bool {
            $variants = $this->graphQlVariables($request)['input']['variants'] ?? [];

            $positions = array_map(
                static fn (array $variant): int => (int) ($variant['position'] ?? 0),
                $variants,
            );

            return $positions === [1, 2];
        });
    }

    public function test_escribe_el_metafield_privado_que_permite_reconocerlo(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        $reference = $product->internal_reference;

        Http::assertSent(function (Request $request) use ($reference): bool {
            foreach ($this->graphQlVariables($request)['input']['metafields'] ?? [] as $metafield) {
                if (($metafield['key'] ?? null) === 'product_studio_id'
                    && ($metafield['value'] ?? null) === $reference) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_sube_los_medios_y_conserva_el_alt(): void
    {
        $product = $this->syncableProduct();
        $this->materializeMedia($product);

        $this->fakeShopify([
            'FileCreate' => $this->fileCreateResponse('gid://shopify/MediaImage/777'),
        ]);

        $result = $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            $files = $this->graphQlVariables($request)['input']['files'] ?? [];

            if ($files === []) {
                return false;
            }

            return ($files[0]['alt'] ?? null) === 'Camiseta de algodón Dies de Platja vista de frente';
        });

        // El GID se devuelve indexado por sha256 para poder guardarlo en local.
        $this->assertSame(
            'gid://shopify/MediaImage/777',
            $result->mediaGids[$product->media->first()->sha256] ?? null,
        );
    }

    public function test_no_vuelve_a_subir_una_imagen_que_ya_tiene_gid(): void
    {
        $product = $this->syncableProduct();

        $product->media->first()->forceFill([
            'shopify_media_gid' => 'gid://shopify/MediaImage/555',
        ])->save();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        // No se ha reservado ninguna subida: se ha reutilizado el GID guardado.
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'stagedUploadsCreate'));
    }

    public function test_actualiza_el_producto_conocido_en_lugar_de_crear_otro(): void
    {
        $product = $this->syncableProduct([
            'shopify_product_gid' => 'gid://shopify/Product/7777777',
        ]);

        $this->fakeShopify([
            'ProductSet' => $this->productSetResponse('gid://shopify/Product/7777777'),
        ]);

        $result = $this->gateway()->createOrUpdateDraft(
            ShopifyProductPayload::fromProduct($product),
            $product->shopify_product_gid,
        );

        Http::assertSent(function (Request $request): bool {
            $identifier = $this->graphQlVariables($request)['identifier'] ?? [];

            // Se actualiza ese producto concreto, no se crea uno nuevo.
            return ($identifier['id'] ?? null) === 'gid://shopify/Product/7777777';
        });

        $this->assertSame('gid://shopify/Product/7777777', $result->productGid);
    }

    public function test_reutiliza_el_producto_existente_si_se_perdio_el_gid(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify([
            'FindProductByStudioId' => ['data' => ['products' => ['nodes' => [[
                'id' => 'gid://shopify/Product/4242',
                'handle' => 'camiseta-marina',
                'status' => 'DRAFT',
                'metafield' => ['value' => $product->internal_reference],
            ]]]]],
            'ProductSet' => $this->productSetResponse('gid://shopify/Product/4242'),
        ]);

        $result = $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            $identifier = $this->graphQlVariables($request)['identifier'] ?? [];

            return ($identifier['id'] ?? null) === 'gid://shopify/Product/4242';
        });

        $this->assertSame('gid://shopify/Product/4242', $result->productGid);
    }

    public function test_ignora_un_resultado_de_busqueda_que_no_es_nuestro(): void
    {
        $product = $this->syncableProduct();

        // El filtro del servidor devuelve un producto ajeno: el valor del
        // metafield no coincide. No debe reutilizarse ese producto.
        $this->fakeShopify([
            'FindProductByStudioId' => ['data' => ['products' => ['nodes' => [[
                'id' => 'gid://shopify/Product/9999',
                'handle' => 'otra-camiseta',
                'status' => 'DRAFT',
                'metafield' => ['value' => 'DDP-OTRA'],
            ]]]]],
        ]);

        $result = $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        $this->assertSame('gid://shopify/Product/1234567890', $result->productGid);
    }

    public function test_rechaza_enviar_algo_que_no_sea_borrador(): void
    {
        $product = $this->syncableProduct();
        $payload = ShopifyProductPayload::fromProduct($product);

        Http::fake();

        $publicable = new ShopifyProductPayload(
            studioId: $payload->studioId,
            title: $payload->title,
            descriptionHtml: $payload->descriptionHtml,
            handle: $payload->handle,
            status: 'ACTIVE',
            vendor: $payload->vendor,
            productType: $payload->productType,
            variants: $payload->variants,
            media: $payload->media,
            tags: $payload->tags,
        );

        try {
            $this->gateway()->createOrUpdateDraft($publicable);
            $this->fail('Debería haber rechazado un estado distinto de DRAFT.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('not_draft', $failure->errorCode);
            $this->assertFalse($failure->isRetryable);
        }

        Http::assertNothingSent();
    }

    public function test_traduce_el_token_invalido_como_error_definitivo(): void
    {
        $product = $this->syncableProduct();

        Http::fake(['*' => Http::response(['errors' => [['message' => 'Invalid API key']]], 401)]);

        try {
            $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));
            $this->fail('Debería haber lanzado ShopifyRequestFailed.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('unauthorized', $failure->errorCode);
            $this->assertFalse($failure->isRetryable, 'Un token inválido no se arregla reintentando.');
        }
    }

    public function test_traduce_el_limite_de_llamadas_como_transitorio(): void
    {
        $product = $this->syncableProduct();

        Http::fake(['*' => Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]], 200)]);

        try {
            $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));
            $this->fail('Debería haber lanzado ShopifyRequestFailed.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('throttled', $failure->errorCode);
            $this->assertTrue($failure->isRetryable, 'El límite de llamadas debe reintentarse.');
        }
    }

    public function test_traduce_los_errores_de_usuario_con_su_motivo_real(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify([
            'ProductSet' => ['data' => ['productSet' => [
                'product' => null,
                'userErrors' => [['field' => ['handle'], 'message' => 'Handle has already been taken']],
            ]]],
        ]);

        try {
            $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));
            $this->fail('Debería haber lanzado ShopifyRequestFailed.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertStringContainsString('Handle has already been taken', $failure->getMessage());
            $this->assertFalse($failure->isRetryable);
        }
    }

    public function test_falla_de_forma_legible_si_no_esta_configurado(): void
    {
        ShopifyInstallation::query()->delete();

        $product = $this->syncableProduct();
        Http::fake();

        $this->assertFalse($this->gateway()->isConfigured());

        try {
            $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));
            $this->fail('Debería haber lanzado ShopifyRequestFailed.');
        } catch (ShopifyRequestFailed $failure) {
            $this->assertSame('not_configured', $failure->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_la_versión_de_api_configurada_aparece_en_la_url(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/admin/api/2026-07/graphql.json');
        });
    }

    public function test_el_token_viaja_en_la_cabecera_y_nunca_en_el_cuerpo(): void
    {
        $product = $this->syncableProduct();

        $this->fakeShopify();

        $this->gateway()->createOrUpdateDraft(ShopifyProductPayload::fromProduct($product));

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Shopify-Access-Token', 'shpat_token-de-prueba')
                && ! str_contains($request->body(), 'shpat_token-de-prueba');
        });
    }

    public function test_el_contrato_se_resuelve_a_la_implementacion_real(): void
    {
        $this->assertInstanceOf(ShopifyProductGatewayImpl::class, app(ShopifyProductGateway::class));
    }
}
